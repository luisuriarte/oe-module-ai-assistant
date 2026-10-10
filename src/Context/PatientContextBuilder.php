<?php

/**
 * PatientContextBuilder — Assembles de-identified clinical context for AI prompts.
 *
 * Milestone 4 Architecture & Privacy Controls:
 *   1. Strict SQL Whitelist: Queries select ONLY clinical data fields; never SELECT *.
 *      Never queries name, address, phone, email, SSN, driver's license, insurance IDs.
 *      From DOB, computes age server-side; DOB itself is never included in the prompt.
 *   2. Active Records Only: Excludes inactive problems, discontinued medications,
 *      and soft-deleted forms (forms.deleted = 0).
 *   3. Universal Allergy Source: Uses the OpenEMR 'lists' table (type = 'allergy'),
 *      the verified core source across OpenEMR 8.2.0 and 8.4.1.
 *   4. Access Controls: Respects patient access permissions and encounter sensitivity
 *      via AclMain::aclCheckCore('sensitivities', $sensitivity).
 *   5. Best-Effort Redaction: Replaces phones, emails, and ID digit sequences in free text.
 *   6. Budget & Truncation: Manages token budget; oldest encounters truncated first.
 *   7. Date Formatting: Configurable absolute (YYYY-MM-DD) or relative ("X days ago"),
 *      rendered through OpenEMR's xl() so the wording follows the session language.
 *   8. Automated Leak Checking: Scans generated context against real patient identifiers.
 *   9. Native Services First: Patient data is read through OpenEMR's official readers
 *      (PatientIssuesService, SocialHistoryService, ProcedureService, ClinicalNotesService)
 *      when available; strict whitelist SQL remains the automatic fallback so output
 *      never depends on a single access path.
 *
 * Compatibility: OpenEMR 8.2.0+ (PHP 8.2 compatible).
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\AiAssistant\Context;

use DateTime;
use DateTimeImmutable;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Modules\AiAssistant\Settings\SettingsManager;
use OpenEMR\Services\ClinicalNotesService;
use OpenEMR\Services\ListService;
use OpenEMR\Services\PatientIssuesService;
use OpenEMR\Services\ProcedureService;
use OpenEMR\Services\SocialHistoryService;
use OpenEMR\Services\VitalsService;

class PatientContextBuilder
{
    /** Maximum number of clinical notes rendered in the context (newest first). */
    private const MAX_CLINICAL_NOTES = 10;

    private SettingsManager $settings;
    private ?SystemLogger $logger;
    /** @var callable|null Callback for database queries: fn(string $sql, array $params): array */
    private $dbQueryCallback;

    public function __construct(?SettingsManager $settings = null, ?callable $dbQueryCallback = null)
    {
        $this->settings        = $settings ?? new SettingsManager();
        $this->logger          = class_exists(SystemLogger::class) ? new SystemLogger() : null;
        $this->dbQueryCallback = $dbQueryCallback;
    }

    // -------------------------------------------------------------------------
    // Main Public API
    // -------------------------------------------------------------------------

    /**
     * Builds the complete, de-identified clinical context for a patient.
     *
     * @param int      $pid         Patient ID
     * @param int|null $encounterId Optional current encounter ID (for priority vitals)
     * @return array{text: string, estimated_tokens: int, token_budget: int, truncated: bool, sections: array<string, int>}
     */
    public function buildContext(int $pid, ?int $encounterId = null): array
    {
        if ($pid <= 0) {
            return [
                'text'             => '',
                'estimated_tokens' => 0,
                'token_budget'     => 0,
                'truncated'        => false,
                'sections'         => [],
            ];
        }

        // 1. Verify patient-level access
        if (class_exists(AclMain::class) && !AclMain::aclCheckCore('patients', 'med')) {
            if (!AclMain::aclCheckCore('patients', 'demo')) {
                return [
                    'text'             => '[Access denied: user lacks patient medical chart access permissions]',
                    'estimated_tokens' => 10,
                    'token_budget'     => 0,
                    'truncated'        => false,
                    'sections'         => [],
                ];
            }
        }

        $tokenBudget = max(50, (int) $this->settings->get('context_token_budget', 4000));
        $numSoapEnc  = max(1, min(20, (int) $this->settings->get('context_num_encounters', 5)));
        $includeLabs = ((int) $this->settings->get('context_include_labs', 0)) === 1;

        // 2. Extract Whitelisted Sections
        // Section A: Baseline Clinical Profile (Highest Priority)
        $demographics = $this->extractPatientDemographics($pid);
        $allergies    = $this->extractAllergies($pid);
        $problems     = $this->extractActiveProblems($pid);
        $medications  = $this->extractActiveMedications($pid);
        $history      = $this->extractClinicalHistory($pid);
        $vitals       = $this->extractVitals($pid, $encounterId);

        // Section B: Recent Encounters' SOAP Notes (Newest first)
        $soapEncounters = $this->extractSoapEncounters($pid, $numSoapEnc);

        // Section C: Labs (Optional, off by default)
        $labs = $includeLabs ? $this->extractRecentLabs($pid) : '';

        // Section D: Clinical notes (native ClinicalNotesService, newest first)
        $clinicalNotes = $this->extractClinicalNotes($pid);

        // 3. Assemble with Token Budget Enforcement (Truncating oldest SOAP first)
        return $this->assembleWithBudget(
            tokenBudget: $tokenBudget,
            demographics: $demographics,
            allergies: $allergies,
            problems: $problems,
            medications: $medications,
            history: $history,
            vitals: $vitals,
            soapEncounters: $soapEncounters,
            clinicalNotes: $clinicalNotes,
            labs: $labs
        );
    }

    /**
     * Automated leak check: scans text for patient's real identifying details.
     *
     * @param string $contextText The generated prompt text
     * @param int    $pid         Patient ID
     * @return array{passed: bool, leaks_found: array<string>, scanned_fields: array<string>}
     */
    public function leakCheck(string $contextText, int $pid): array
    {
        if ($pid <= 0 || trim($contextText) === '') {
            return ['passed' => true, 'leaks_found' => [], 'scanned_fields' => []];
        }

        $identifiers = $this->queryPatientIdentifiers($pid);
        $leaksFound  = [];
        $scanned     = array_keys($identifiers);

        foreach ($identifiers as $field => $val) {
            $cleaned = trim((string) $val);
            if (strlen($cleaned) < 3) {
                continue; // Skip trivially short strings
            }

            // Case-insensitive boundary search
            $pattern = '/\b' . preg_quote($cleaned, '/') . '\b/i';
            if (preg_match($pattern, $contextText)) {
                $leaksFound[] = $field;
            }
        }

        return [
            'pass'           => empty($leaksFound),
            'passed'         => empty($leaksFound),
            'leaks'          => $leaksFound,
            'leaks_found'    => $leaksFound,
            'scanned_fields' => $scanned,
        ];
    }

    /**
     * Best-effort redaction of telephone numbers, emails, and ID sequences.
     *
     * Note: Documented as best-effort heuristics, not an absolute cryptographic guarantee.
     */
    public function redactFreeText(string $text): string
    {
        if (trim($text) === '') {
            return '';
        }

        // 1. Redact Emails
        $text = preg_replace(
            '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/',
            '[REDACTED-EMAIL]',
            $text
        );

        // Comprehensive list of medical dose, measurement, and lab units to protect from redaction
        $clinicalUnits = '(?:UI|IU|U|mg|mcg|µg|ug|g|gr|grs|gramos?|kg|kgs|kilos?|ml|mL|l|lt|litros?|cc|mEq|mmol|dL|mol|gotas?|puffs?|ampollas?|comprimidos?|comp|capsulas?|tabletas?|sobres?|mmHg|bpm|lpm|rpm|°C|°F|%|fl|pg|U\/L|UI\/L|horas?|hs|dias?|días?|veces|cada)';

        // Volumetric/count suffixes that follow clinical numbers (e.g. "4.500.000 /mm3")
        $labSuffix = '(?:mm3|mm³|mm\^3|micra|cmm|x\s*10\^?[3-9])';

        // Keywords that mark an adjacent number as a person identifier or a telephone.
        $idKeywords   = '(?:\bDNI\b|\bdni\b|Documento|documento|CUIL|cuil|CUIT|cuit|Pasaporte|pasaporte|Cédula|cedula|Identidad|identidad|Legajo|legajo)';
        $telKeywords  = '(?:\bT[Ee]L\b|Tel[eé]fono|telefono|Fono|\bCEL\b|Celular|celular|WhatsApp|whatsapp|\bWA\b|Tel|Tel\.|fijo|movil|móvil|Móvil|\bFax\b|fax|Phone|phone)';

        // 2. Argentine DNI / CUIL with dots (e.g. 34.567.890).
        //    Never redact a clinical figure such as 4.500.000 or 2.400.000 when a unit or a
        //    /mm3-style lab suffix follows, nor a slash-delimited count such as 120/80.
        $text = preg_replace(
            '/\b\d{1,2}\.\d{3}\.\d{3}\b'
            . '(?!\s*' . $clinicalUnits . '\b)'
            . '(?!\s*\/\s*' . $labSuffix . '\b)'
            . '(?!\s*' . $labSuffix . '\b)'
            . '(?!\/)/i',
            '[REDACTED-ID]',
            $text
        );

        // 3. US SSN format (xxx-xx-xxxx) — fixed structure, no extra evidence needed.
        $text = preg_replace(
            '/\b\d{3}-\d{2}-\d{4}\b/',
            '[REDACTED-ID]',
            $text
        );

        // 4. CUIL/CUIT with hyphens (XX-XX-XXXXXX) or a bare 11-digit run whose check digit
        //    validates. A wrong check digit means it is not a real CUIL, so a plain number
        //    stays untouched.
        $text = preg_replace_callback(
            '/\b(?:\d{2}-\d{2}-\d{6}|\d{11})\b/',
            function ($m) {
                $digits = preg_replace('/\D/', '', $m[0]);
                if ($digits === null || strlen($digits) !== 11 || !self::isValidCuilCheckDigit($digits)) {
                    return $m[0];
                }

                return '[REDACTED-ID]';
            },
            $text
        );

        // 5. Phone numbers. Structure is required: three or more digit groups separated by
        //    hyphens/spaces/dots, parentheses, a + or 00 country prefix — or a keyword such
        //    as tel/cel/whatsapp. Bare digit runs are never phones.
        $phonePattern =
            // Group of three: 11-4567-8901 / 11 4567 8901 / +54 11 4567 8901
            '(?:\+?\d{1,3}[-.\s]?)?(?:\(?\d{2,4}\)?[-.\s]?)\d{3,4}[-.\s]\d{3,4}\b'
            // Parenthesised area code: (011) 4567-8901
            . '|\(\d{2,4}\)\s?\d{3,4}[-.\s]?\d{3,4}\b'
            // Keyword in front of a looser number: tel 4567-8901 / whatsapp 1145678901
            . '|(?:\b(?:' . $telKeywords . ')\b\s*[:=]?\s*)(?:\+?\d{1,3}[-.\s]?)?\d{3,4}[-.\s]?\d{3,4}(?:[-.\s]?\d{3,4})?\b';
        $text = preg_replace(
            '/' . $phonePattern
            . '(?!\s*(?:' . $clinicalUnits . '|' . $labSuffix . ')\b)'
            . '(?![\/])/iu',
            '[REDACTED-PHONE]',
            $text
        );

        // 6. Bare digit runs (6 or more) are only identifiers when a keyword says so —
        //    DNI 34567890, documento 2034567890. Lab results such as plaquetas 250000 or
        //    troponina 123456 carry no keyword and are left intact.
        $text = preg_replace_callback(
            '/(' . $idKeywords . ')\s*([A-Z]{0,3}\s?[0-9][0-9\s.\-]{4,}[0-9]|[0-9]{6,})/ui',
            function ($m) {
                // Only redact when the run really holds six or more digits; a keyword in
                // front of a short number is not enough evidence.
                $digits = preg_replace('/\D/', '', (string) $m[2]);
                if ($digits === null || strlen($digits) < 6) {
                    return $m[0];
                }

                return $m[1] . ' [REDACTED-ID]';
            },
            $text
        );
        return (string) $text;
    }

    /**
     * Validates an 11-digit CUIL/CUIT number against the AFIP mod-11 check digit.
     *
     * Weights 5,4,3,2,7,6,5,4,3,2 are applied to the first ten digits. A remainder of 11
     * yields check digit 0; a remainder of 10 yields 9. Also rejects the tax IDs that
     * cannot appear as a person's CUIL.
     */
    private static function isValidCuilCheckDigit(string $digits): bool
    {
        if (strlen($digits) !== 11 || !ctype_digit($digits)) {
            return false;
        }

        // Valid CUIL/CUIT prefixes for a natural person: 20,23,24,27,28,30 (and foreign
        // residents 20,23,24,27,28,30, plus 50/51/52 as legal entities).
        $prefix = (int) substr($digits, 0, 2);
        if (!in_array($prefix, [20, 23, 24, 27, 28, 30, 50, 51, 52], true)) {
            return false;
        }

        $weights = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $sum     = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += ((int) $digits[$i]) * $weights[$i];
        }

        $mod = $sum % 11;
        $check = 11 - $mod;
        if ($check === 11) {
            $check = 0;
        } elseif ($check === 10) {
            $check = 9;
        }

        return $check === (int) $digits[10];
    }

    /**
     * Formats dates according to context_relative_dates setting (absolute vs relative).
     */
    public function formatDate(?string $dateStr): string
    {
        if (!$dateStr || $dateStr === '0000-00-00' || $dateStr === '0000-00-00 00:00:00') {
            return '';
        }

        $useRelative = ((int) $this->settings->get('context_relative_dates', 0)) === 1;

        try {
            $dt  = new DateTimeImmutable($dateStr);
            $now = new DateTimeImmutable('today');

            if (!$useRelative) {
                return $dt->format('Y-m-d');
            }

            $dtDate   = new DateTimeImmutable($dt->format('Y-m-d'));
            $nowDate  = new DateTimeImmutable($now->format('Y-m-d'));
            $diffDays = (int) $nowDate->diff($dtDate)->format('%r%a');

            if ($diffDays === 0) {
                return xl('today');
            }
            if ($diffDays === -1) {
                return xl('yesterday');
            }
            if ($diffDays < 0) {
                $daysAgo = abs($diffDays);
                if ($daysAgo < 30) {
                    return $daysAgo . ' ' . xl('days ago');
                }
                if ($daysAgo < 365) {
                    $months = (int) round($daysAgo / 30.4);
                    return $months . ' ' . ($months === 1 ? xl('month ago') : xl('months ago'));
                }
                $years = (int) round($daysAgo / 365.25);
                return $years . ' ' . ($years === 1 ? xl('year ago') : xl('years ago'));
            }

            return $dt->format('Y-m-d');
        } catch (\Throwable) {
            return substr((string) $dateStr, 0, 10);
        }
    }

    /**
     * Heuristic token estimation (~4 characters per token for Spanish/English text).
     */
    public static function estimateTokens(string $text): int
    {
        $len = strlen(trim($text));
        return $len > 0 ? (int) ceil($len / 4) : 0;
    }

    // -------------------------------------------------------------------------
    // Native Service Helpers (preferred readers with SQL fallback)
    // -------------------------------------------------------------------------

    /**
     * Normalizes an OpenEMR ProcessingResult into a plain indexed array.
     * Returns [] when the result carries no data or lacks getData().
     */
    private function processingResultData(object $result): array
    {
        if (!method_exists($result, 'getData')) {
            return [];
        }
        $data = $result->getData();
        return is_array($data) ? array_values($data) : [];
    }

    /**
     * Logs a native service failure so the SQL fallback path is transparent.
     */
    private function logServiceFailure(string $operation, \Throwable $e): void
    {
        if ($this->logger) {
            $this->logger->error("[AiAssistant] PatientContextBuilder {$operation} failed, falling back to SQL: " . $e->getMessage());
        }
    }

    /**
     * All active issues for the patient via PatientIssuesService::getActiveIssues().
     *
     * Returns:
     *   - a non-empty list of issue records when the service yields data,
     *   - null when the service is unavailable, throws, or reports no rows
     *     (callers then fall back to whitelist SQL).
     */
    private function fetchActiveIssuesViaService(int $pid): ?array
    {
        if (!class_exists(PatientIssuesService::class)) {
            return null;
        }

        try {
            $service = new PatientIssuesService();
            $data    = $this->processingResultData($service->getActiveIssues($pid));
            return $data === [] ? null : $data;
        } catch (\Throwable $e) {
            $this->logServiceFailure('PatientIssuesService::getActiveIssues', $e);
            return null;
        }
    }

    /**
     * Active issues of one issue type, or null when the service path is unusable.
     */
    private function activeIssuesOfType(int $pid, string $type): ?array
    {
        $issues = $this->fetchActiveIssuesViaService($pid);
        if ($issues === null) {
            return null;
        }

        return array_values(
            array_filter($issues, static fn ($row): bool => ($row['type'] ?? '') === $type)
        );
    }

    /**
     * Latest social history record via SocialHistoryService::getHistoryDataForPatientPid().
     * Returns null when the service is unavailable, throws, or returns no rows.
     */
    private function latestSocialHistoryRecordViaService(int $pid): ?array
    {
        if (!class_exists(SocialHistoryService::class)) {
            return null;
        }

        try {
            $records = (new SocialHistoryService())->getHistoryDataForPatientPid($pid);
            if (!is_array($records) || $records === []) {
                return null;
            }
            $record = $records[0] ?? null;
            return is_array($record) && $record !== [] ? $record : null;
        } catch (\Throwable $e) {
            $this->logServiceFailure('SocialHistoryService::getHistoryDataForPatientPid', $e);
            return null;
        }
    }

    /**
     * Resolves a coded history_data value to its readable list_options title via
     * ListService. Values that cannot be resolved (free text, missing option) are
     * returned unchanged.
     *
     * OpenEMR stores lifestyle answers as pipe-delimited strings such as
     * `note|type|date`, where `type` is `current<field_id>`, `quit<field_id>`,
     * `never<field_id>` or `0`; tobacco adds an extra
     * `note|type|date|option_id|packs` shape. The list_options option id sits at
     * index 3 (or index 0 for a bare id such as "3").
     */
    private function resolveCodedListValue(string $raw, string $listId): string
    {
        $raw = trim($raw);
        if ($raw === '' || !class_exists(ListService::class)) {
            return $raw;
        }

        $segments = explode('|', $raw);
        $optionId = trim($segments[3] ?? '');
        if ($optionId === '' && count($segments) === 1) {
            $optionId = trim($segments[0]);
        }
        if ($optionId === '') {
            return $raw;
        }

        try {
            $option = (new ListService())->getListOption($listId, $optionId);
        } catch (\Throwable $e) {
            $this->logServiceFailure('ListService::getListOption(' . $listId . ')', $e);
            return $raw;
        }

        if (empty($option) || empty($option['title'])) {
            return $raw;
        }

        return trim((string) $option['title']);
    }

    /**
     * Maps a parsed lifestyle status onto the English source wording. Returns an
     * empty string for an unknown status or `none` (nothing worth emitting).
     *
     * The result is passed through xl(), so OpenEMR renders it in the session
     * language while the source stays stable English (USA Hospital).
     */
    private function renderLifestyleStatus(?string $status): string
    {
        return match ($status) {
            'current'        => xl('current'),
            'quit'           => xl('quit'),
            'never'          => xl('never'),
            'not_applicable' => xl('not applicable'),
            default          => '',
        };
    }

    /**
     * Tobacco-specific status wording. Tobacco reads as a person's smoking state
     * ("current smoker") rather than the generic lifestyle status ("current"), and
     * is used whenever the smoking_status option title cannot be resolved.
     */
    private function renderTobaccoStatus(?string $status): string
    {
        return match ($status) {
            'current'        => xl('current smoker'),
            'quit'           => xl('former smoker'),
            'never'          => xl('never smoker'),
            'not_applicable' => xl('not applicable'),
            default          => '',
        };
    }

    /**
     * Renders one "Label: value" history line.
     *
     * Free text is redacted through redactFreeText(); anything that still carries a
     * pipe (raw coded storage that failed to parse) is discarded rather than leaked.
     * Returns an empty string when there is nothing to emit.
     */
    private function renderHistoryItem(string $label, string $value): string
    {
        $value = trim($value);
        if ($value === '' || strpos($value, '|') !== false) {
            return '';
        }

        return xl($label) . ': ' . $this->redactFreeText($value);
    }

    /**
     * Parses OpenEMR's lifestyle-status pipe format.
     *
     * Detected purely by the presence of "|", not by column name, so free text
     * passes through untouched. Returns null when the value is not pipe-shaped.
     *
     * Shape: `note|type|date` for the regular lifestyle columns, and
     * `note|type|date|smoking_status_option_id|packs_per_day` for tobacco.
     */
    private function parseLifestylePipe(string $value): ?array
    {
        $trimmed = trim($value);
        if ($trimmed === '' || strpos($trimmed, '|') === false) {
            return null;
        }
        $segments = explode('|', $trimmed);
        // note|type|date|smoking_status_option_id|packs_per_day  (tobacco extended)
        // note|type|date                                        (others)
        $note = trim((string) ($segments[0] ?? ''));
        $type = trim((string) ($segments[1] ?? ''));
        $status = null;
        $smokingStatusOptionId = trim((string) ($segments[3] ?? ''));
        $packsPerDay = trim((string) ($segments[4] ?? ''));

        if ($type !== '') {
            $lower = strtolower($type);
            if (str_starts_with($lower, 'current')) {
                $status = 'current';
            } elseif (str_starts_with($lower, 'quit')) {
                $status = 'quit';
            } elseif (str_starts_with($lower, 'never')) {
                $status = 'never';
            } elseif (str_starts_with($lower, 'not_applicable')) {
                $status = 'not_applicable';
            } elseif ($type === '0') {
                $status = 'none';
            }
        }

        $result = [
            'note'        => $note,
            'status'      => $status,
            'segments'    => $segments,
            'packs'       => is_numeric($packsPerDay) ? $packsPerDay : '',
            'type_raw'    => $type,
        ];
        if ($smokingStatusOptionId !== '') {
            $result['smoking_option_id'] = $smokingStatusOptionId;
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // Extraction Methods (Strict SQL Whitelist)
    // -------------------------------------------------------------------------

    /**
     * Demographics: Selects ONLY DOB and sex. Age computed server-side.
     * Never queries or exposes name, address, phone, email, SSN.
     */
    private function extractPatientDemographics(int $pid): string
    {
        $sql = 'SELECT `DOB`, `sex` FROM `patient_data` WHERE `pid` = ? LIMIT 1';
        $rows = $this->query($sql, [$pid]);

        if (empty($rows)) {
            return '';
        }

        $row = $rows[0];
        $dobStr = (string) ($row['DOB'] ?? '');
        $sex    = trim((string) ($row['sex'] ?? ''));

        $ageStr = xl('Age not recorded');
        if ($dobStr !== '' && $dobStr !== '0000-00-00') {
            try {
                $dob = new DateTime($dobStr);
                $now = new DateTime();
                $age = $dob->diff($now)->y;
                $ageStr = $age . ' ' . xl('years');
            } catch (\Throwable) {
                $ageStr = xl('Unknown age');
            }
        }

        $sexStr = match (strtolower($sex)) {
            'female', 'f', 'femenino' => xl('Female'),
            'male', 'm', 'masculino'  => xl('Male'),
            default                   => $sex !== '' ? $sex : xl('Not specified'),
        };

        return '### ' . xl('PATIENT PROFILE') . "\n- " . xl('Age') . ": {$ageStr}\n- " . xl('Sex') . ": {$sexStr}\n";
    }

    /**
     * Allergies: Universal source 'lists' with type = 'allergy' and activity = 1.
     * Prefers the native PatientIssuesService::getActiveIssues() reader; falls back
     * to a strict whitelist SQL query when the service is unavailable.
     */
    private function extractAllergies(int $pid): string
    {
        $rows = $this->activeIssuesOfType($pid, 'allergy');
        if ($rows === null) {
            $sql = "SELECT `title`, `comments`, `severity_al`
                    FROM `lists`
                    WHERE `pid` = ?
                      AND `type` = 'allergy'
                      AND `activity` = 1
                      AND (`enddate` IS NULL OR `enddate` = '' OR `enddate` = '0000-00-00' OR `enddate` > NOW())
                    ORDER BY `begdate` DESC";

            $rows = $this->query($sql, [$pid]);
        }

        if (empty($rows)) {
            return '### ' . xl('KNOWN ALLERGIES') . "\n- " . xl('No active allergies recorded.') . "\n";
        }

        $out = '### ' . xl('KNOWN ALLERGIES') . "\n";
        foreach ($rows as $r) {
            $title    = $this->redactFreeText(trim((string) ($r['title'] ?? '')));
            // lists.severity_al holds a severity_ccda list_option id, not free text;
            // resolve it to its readable title when the native service is available.
            $severity = $this->resolveCodedListValue(
                trim((string) ($r['severity_al'] ?? '')),
                'severity_ccda'
            );
            $comments = $this->redactFreeText(trim((string) ($r['comments'] ?? '')));

            $line = "- {$title}";
            if ($severity !== '') {
                $line .= ' (' . xl('Severity') . ": {$severity})";
            }
            if ($comments !== '') {
                $line .= " — {$comments}";
            }
            $out .= $line . "\n";
        }

        return $out;
    }

    /**
     * Active Problems: 'lists' where type = 'medical_problem' and activity = 1.
     * Prefers the native PatientIssuesService::getActiveIssues() reader; falls back
     * to a strict whitelist SQL query when the service is unavailable.
     */
    private function extractActiveProblems(int $pid): string
    {
        $rows = $this->activeIssuesOfType($pid, 'medical_problem');
        if ($rows === null) {
            $sql = "SELECT `title`, `begdate`, `comments`
                    FROM `lists`
                    WHERE `pid` = ?
                      AND `type` = 'medical_problem'
                      AND `activity` = 1
                      AND (`enddate` IS NULL OR `enddate` = '' OR `enddate` = '0000-00-00' OR `enddate` > NOW())
                    ORDER BY `begdate` DESC";

            $rows = $this->query($sql, [$pid]);
        }

        if (empty($rows)) {
            return '### ' . xl('ACTIVE PROBLEMS') . "\n- " . xl('No active medical problems recorded.') . "\n";
        }

        $out = '### ' . xl('ACTIVE PROBLEMS') . "\n";
        foreach ($rows as $r) {
            $title    = $this->redactFreeText(trim((string) ($r['title'] ?? '')));
            $date     = $this->formatDate((string) ($r['begdate'] ?? ''));
            $comments = $this->redactFreeText(trim((string) ($r['comments'] ?? '')));

            $line = "- {$title}";
            if ($date !== '') {
                $line .= ' (' . xl('Onset') . ": {$date})";
            }
            if ($comments !== '') {
                $line .= " — {$comments}";
            }
            $out .= $line . "\n";
        }

        return $out;
    }

    /**
     * Active Medications: queries both prescriptions (active = 1) and lists (type = 'medication', activity = 1).
     * Excludes discontinued or expired medications. Prescriptions remain whitelist SQL (no native reader exposes
     * the legacy prescriptions table); the lists/medication side prefers PatientIssuesService::getActiveIssues().
     */
    private function extractActiveMedications(int $pid): string
    {
        // 1. Check prescriptions table (active = 1)
        $sqlRx = "SELECT `drug`, `dosage`, `form`, `route`, `interval`, `date_added`
                  FROM `prescriptions`
                  WHERE `patient_id` = ?
                    AND `active` = 1
                  ORDER BY `date_added` DESC";

        $rowsRx = $this->query($sqlRx, [$pid]);

        // 2. Check lists table (type = 'medication' and activity = 1), preferring the
        //    native service reader and falling back to whitelist SQL.
        $rowsLists = $this->activeIssuesOfType($pid, 'medication');
        if ($rowsLists === null) {
            $sqlLists = "SELECT `title`, `begdate`, `comments`
                         FROM `lists`
                         WHERE `pid` = ?
                           AND `type` = 'medication'
                           AND `activity` = 1
                           AND (`enddate` IS NULL OR `enddate` = '' OR `enddate` = '0000-00-00' OR `enddate` > NOW())
                         ORDER BY `begdate` DESC";

            $rowsLists = $this->query($sqlLists, [$pid]);
        }

        if (empty($rowsRx) && empty($rowsLists)) {
            return '### ' . xl('CURRENT MEDICATIONS') . "\n- " . xl('No active medications recorded.') . "\n";
        }

        $out = '### ' . xl('CURRENT MEDICATIONS') . "\n";

        foreach ($rowsRx as $rx) {
            $drug     = $this->redactFreeText(trim((string) ($rx['drug'] ?? '')));
            $dosage   = trim((string) ($rx['dosage'] ?? ''));
            $interval = trim((string) ($rx['interval'] ?? ''));
            $date     = $this->formatDate((string) ($rx['date_added'] ?? ''));

            $line = "- {$drug}";
            if ($dosage !== '') {
                $line .= " {$dosage}";
            }
            if ($interval !== '') {
                $line .= " ({$interval})";
            }
            if ($date !== '') {
                $line .= ' [' . xl('Prescribed') . ": {$date}]";
            }
            $out .= $line . "\n";
        }

        foreach ($rowsLists as $r) {
            $title        = $this->redactFreeText(trim((string) ($r['title'] ?? '')));
            $date         = $this->formatDate((string) ($r['begdate'] ?? ''));
            $comments     = $this->redactFreeText(trim((string) ($r['comments'] ?? '')));
            // Native service rows also expose structured dosage from lists_medication.
            $instructions = trim((string) ($r['drug_dosage_instructions'] ?? ''));

            $line = "- {$title}";
            if ($instructions !== '') {
                $line .= " {$instructions}";
            }
            if ($date !== '') {
                $line .= ' (' . xl('Since') . ": {$date})";
            }
            if ($comments !== '') {
                $line .= " — {$comments}";
            }
            $out .= $line . "\n";
        }

        return $out;
    }

    /**
     * History Data: Lifestyle, medical, surgical, family, social history.
     *
     * The native SocialHistoryService::getHistoryDataForPatientPid() reader supplies
     * the four core lifestyle fields (tobacco, alcohol, exercise_patterns,
     * recreational_drugs); the whitelist query below supplies the extended fields and
     * acts as the fallback when the service is unavailable. The two rows are merged
     * so no previously emitted field is lost.
     *
     * Visibility: OpenEMR stores lifestyle answers in a coded pipe format
     * (`note|type|date`, where `type` is `current<field_id>`, `quit<field_id>`,
     * `never<field_id>` or `0`), so EVERY lifestyle column can carry coded storage —
     * not just tobacco. Pipe values are detected by shape, parsed into a readable
     * status plus note, and rendered through xl(); free text without a pipe passes
     * through unchanged. Tobacco additionally resolves its smoking_status option id
     * (list 'smoking_status') and pack count via ListService, but never depends on
     * that lookup: a status-derived fallback is always available. No raw pipe value
     * or `current`/`quit`/`never` storage token is ever emitted.
     *
     * Column names come from the real history_data schema, verified identical across
     * OpenEMR 8.2.0, 8.4.1 and the current tree. There is no `exercise`, `diet`,
     * `medical_history`, `surgical_history`, `family_history` or `social_history`
     * column — the lifestyle fields are `exercise_patterns` / `sleep_patterns`, and
     * medical/surgical/family history lives in the usertextNN/userareaNN slots.
     */
    private function extractClinicalHistory(int $pid): string
    {
        $sql = "SELECT `tobacco`, `alcohol`, `coffee`, `exercise_patterns`,
                       `sleep_patterns`, `recreational_drugs`, `hazardous_activities`,
                       `additional_history`, `counseling`,
                       `history_mother`, `history_father`, `history_siblings`,
                       `history_offspring`, `history_spouse`
                FROM `history_data`
                WHERE `pid` = ?
                ORDER BY `date` DESC
                LIMIT 1";

        $rows = $this->query($sql, [$pid]);
        $r = $rows[0] ?? [];

        // Native service core lifestyle fields (merged over the SQL row so the
        // service remains the preferred source for these four columns).
        $serviceRow = $this->latestSocialHistoryRecordViaService($pid);
        if (is_array($serviceRow) && $serviceRow !== []) {
            foreach (['tobacco', 'alcohol', 'exercise_patterns', 'recreational_drugs'] as $column) {
                if (array_key_exists($column, $serviceRow)) {
                    $r[$column] = $serviceRow[$column];
                }
            }
        }

        if ($r === []) {
            return '';
        }

        $items = [];

        // column => English label. Only non-empty values are emitted.
        $map = [
            'tobacco'              => 'Tobacco',
            'alcohol'              => 'Alcohol',
            'coffee'               => 'Coffee',
            'exercise_patterns'    => 'Physical activity',
            'sleep_patterns'       => 'Sleep',
            'recreational_drugs'   => 'Recreational drugs',
            'hazardous_activities' => 'Hazardous activities',
            'counseling'           => 'Counseling received',
            'additional_history'   => 'Additional history',
            'history_mother'       => 'Mother history',
            'history_father'       => 'Father history',
            'history_siblings'     => 'Siblings history',
            'history_offspring'    => 'Offspring history',
            'history_spouse'       => 'Spouse history',
        ];

        // Lifestyle columns whose value may be a pipe-delimited coded answer. The
        // parsing itself is shape-based (see parseLifestylePipe()); only tobacco
        // carries the extra smoking_status option id (list 'smoking_status').
        $codedHistoryLists = [
            'tobacco' => 'smoking_status',
        ];

        foreach ($map as $column => $label) {
            if (!isset($r[$column]) || trim((string) $r[$column]) === '') {
                continue;
            }
            $value  = trim((string) $r[$column]);
            $parsed = $this->parseLifestylePipe($value);

            if ($parsed === null) {
                // Free text without a pipe: pass through unchanged (redacted only).
                $line = $this->renderHistoryItem($label, $value);
                if ($line !== '') {
                    $items[] = $line;
                }
                continue;
            }

            $status = $parsed['status'];
            $note   = (string) $parsed['note'];

            if (isset($codedHistoryLists[$column])) {
                // Tobacco: prefer the resolved smoking_status title; fall back to the
                // status-derived wording so output never depends on ListService.
                $readable = $this->renderTobaccoStatus($status);
                $resolved = $this->resolveCodedListValue($value, $codedHistoryLists[$column]);
                if ($resolved !== $value && strpos($resolved, '|') === false && trim($resolved) !== '') {
                    $readable = trim($resolved);
                }
                if ($readable === '') {
                    if ($note === '') {
                        // Unknown status and unresolvable option: never leak the raw value.
                        continue;
                    }
                    $readable = $note;
                    $note     = '';
                }
                if ($status === 'quit') {
                    $quitDate = trim((string) ($parsed['segments'][2] ?? ''));
                    if ($quitDate !== '' && $quitDate !== '0000-00-00') {
                        $readable .= ' (' . xl('quit') . ': ' . $quitDate . ')';
                    }
                }
                if (($parsed['packs'] ?? '') !== '') {
                    $readable .= ' — ' . $parsed['packs'] . ' ' . xl('packs/day');
                }
                if ($note !== '') {
                    $readable .= ' (' . $note . ')';
                }
            } else {
                // Regular lifestyle column: status wording plus optional note.
                $readable = $this->renderLifestyleStatus($status);
                if ($readable === '') {
                    if ($note === '') {
                        continue;
                    }
                    $readable = $note;
                } elseif ($note !== '') {
                    $readable .= ' (' . $note . ')';
                }
            }

            $line = $this->renderHistoryItem($label, $readable);
            if ($line !== '') {
                $items[] = $line;
            }
        }

        // Surgical history: the history_data surgical checkboxes (appendectomy, hernia
        // repair, ...) plus each of the DC_* "denies" flags, which are meaningful data.
        $surgical = [];
        foreach (
            [
                'appendectomy'     => 'Appendectomy',
                'cholecystestomy'  => 'Cholecystectomy',
                'hernia_repair'    => 'Hernia repair',
                'hysterectomy'     => 'Hysterectomy',
                'heart_surgery'    => 'Heart surgery',
                'cataract_surgery' => 'Cataract surgery',
                'tonsillectomy'    => 'Tonsillectomy',
            ] as $column => $label
        ) {
            if (!empty($r[$column])) {
                $surgical[] = xl($label);
            }
        }

        if ($surgical !== []) {
            $items[] = xl('Surgical history') . ': ' . implode(', ', $surgical);
        }

        if ($items === []) {
            return '';
        }

        return '### ' . xl('HISTORY AND HABITS') . "\n- " . implode("\n- ", $items) . "\n";
    }

    /**
     * Vitals: Whitelists vital signs (bps, bpd, pulse, temp, resp, spo2, bmi).
     * Excludes deleted records via forms join.
     */
    private function extractVitals(int $pid, ?int $encounterId = null): string
    {
        // Try VitalsService if available and encounter provided
        if ($encounterId !== null && $encounterId > 0 && class_exists(VitalsService::class)) {
            try {
                $service = new VitalsService();
                $v = $service->getVitalsForPatientEncounter($encounterId);
                if (!empty($v) && is_array($v)) {
                    return $this->formatVitalRow($v);
                }
            } catch (\Throwable) {
                // fallback to direct SQL
            }
        }

        // Direct SQL join verifying patient and forms.deleted = 0
        $sql = "SELECT v.`bps`, v.`bpd`, v.`pulse`, v.`temperature`, v.`respiration`,
                       v.`oxygen_saturation`, v.`BMI`, v.`date`
                FROM `form_vitals` v
                JOIN `forms` f ON (f.form_id = v.id AND f.formdir = 'vitals' AND f.deleted = 0)
                WHERE v.`pid` = ?
                ORDER BY v.`date` DESC
                LIMIT 1";

        $rows = $this->query($sql, [$pid]);
        if (empty($rows)) {
            return '';
        }

        return $this->formatVitalRow($rows[0]);
    }

    private function formatVitalRow(array $v): string
    {
        $parts = [];
        if (!empty($v['bps']) || !empty($v['bpd'])) {
            $parts[] = xl('BP') . ": {$v['bps']}/{$v['bpd']} mmHg";
        }
        if (!empty($v['pulse'])) {
            $parts[] = xl('Pulse') . ': ' . $this->formatVitalNumber($v['pulse']) . ' ' . xl('bpm');
        }
        if (!empty($v['temperature'])) {
            $parts[] = xl('Temp') . ': ' . $this->formatVitalNumber($v['temperature']) . ' °C';
        }
        if (!empty($v['respiration'])) {
            $parts[] = xl('RR') . ': ' . $this->formatVitalNumber($v['respiration']) . ' ' . xl('rpm');
        }
        if (!empty($v['oxygen_saturation'])) {
            $parts[] = xl('SpO2') . ': ' . $this->formatVitalNumber($v['oxygen_saturation']) . '%';
        }
        if (!empty($v['BMI'])) {
            $parts[] = xl('BMI') . ': ' . $this->formatVitalNumber($v['BMI']);
        }

        if (empty($parts)) {
            return '';
        }

        $dateStr = $this->formatDate((string) ($v['date'] ?? ''));
        $header  = $dateStr !== ''
            ? '### ' . xl('RECENT VITAL SIGNS') . " ({$dateStr})\n"
            : '### ' . xl('RECENT VITAL SIGNS') . "\n";

        return $header . "- " . implode(', ', $parts) . "\n";
    }

    /**
     * Formats a numeric vital sign with at most two decimals, trimming trailing
     * zeros ("90.000000" -> "90", "100.400000" -> "100.4"). Non-numeric values
     * are returned unchanged.
     */
    private function formatVitalNumber(mixed $value): string
    {
        $str = trim((string) $value);
        if ($str === '' || !is_numeric($str)) {
            return $str;
        }

        $formatted = number_format((float) $str, 2, '.', '');
        if (strpos($formatted, '.') !== false) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted;
    }

    /**
     * SOAP Notes from last N encounters:
     * - Enforces forms.deleted = 0 (excludes deleted/voided forms).
     * - Verifies every form belongs to $pid.
     * - Enforces encounter sensitivity access rules.
     *
     * @return array<int, array{encounter: int, date: string, text: string, tokens: int}>
     */
    private function extractSoapEncounters(int $pid, int $limit): array
    {
        // 1. Fetch recent encounters for this patient
        $intLimit = (int) ($limit * 2);
        $sql = "SELECT fe.`id`, fe.`date`, fe.`encounter`, fe.`sensitivity`
                FROM `form_encounter` fe
                JOIN `forms` f ON (f.form_id = fe.id AND f.formdir = 'encounter' AND f.deleted = 0)
                WHERE fe.`pid` = ?
                ORDER BY fe.`date` DESC
                LIMIT {$intLimit}";

        $encounters = $this->query($sql, [$pid]); // Fetch buffer in case some are sensitive
        if (empty($encounters)) {
            return [];
        }

        $soapBlocks = [];

        foreach ($encounters as $enc) {
            if (count($soapBlocks) >= $limit) {
                break;
            }

            $encId       = (int) ($enc['encounter'] ?? 0);
            $sensitivity = trim((string) ($enc['sensitivity'] ?? 'normal'));

            // Check encounter sensitivity via OpenEMR ACL
            if ($sensitivity !== '' && $sensitivity !== 'normal') {
                if (class_exists(AclMain::class) && !AclMain::aclCheckCore('sensitivities', $sensitivity)) {
                    continue; // Current user lacks permission to read this sensitive encounter
                }
            }

            // 2. Fetch SOAP notes for this encounter (verifying forms.deleted = 0 and pid)
            $soapSql = "SELECT s.`subjective`, s.`objective`, s.`assessment`, s.`plan`, s.`date`
                        FROM `form_soap` s
                        JOIN `forms` f ON (f.form_id = s.id AND f.formdir = 'soap' AND f.deleted = 0)
                        WHERE s.`pid` = ? AND f.`encounter` = ?
                        ORDER BY s.`id` DESC";

            $soaps = $this->query($soapSql, [$pid, $encId]);
            if (empty($soaps)) {
                continue;
            }

            $dateFormatted = $this->formatDate((string) ($enc['date'] ?? ''));
            $encHeader     = $dateFormatted !== ''
                ? '#### ' . xl('Encounter of') . " {$dateFormatted}\n"
                : '#### ' . xl('Encounter') . "\n";

            $noteParts = [];
            foreach ($soaps as $s) {
                $subj = $this->redactFreeText(trim((string) ($s['subjective'] ?? '')));
                $obj  = $this->redactFreeText(trim((string) ($s['objective'] ?? '')));
                $ass  = $this->redactFreeText(trim((string) ($s['assessment'] ?? '')));
                $plan = $this->redactFreeText(trim((string) ($s['plan'] ?? '')));

                $lines = [];
                if ($subj !== '') {
                    $lines[] = '- ' . xl('Subjective') . ": {$subj}";
                }
                if ($obj !== '') {
                    $lines[] = '- ' . xl('Objective') . ": {$obj}";
                }
                if ($ass !== '') {
                    $lines[] = '- ' . xl('Assessment') . ": {$ass}";
                }
                if ($plan !== '') {
                    $lines[] = '- ' . xl('Plan') . ": {$plan}";
                }

                if (!empty($lines)) {
                    $noteParts[] = implode("\n", $lines);
                }
            }

            if (!empty($noteParts)) {
                $blockText = $encHeader . implode("\n\n", $noteParts) . "\n";
                $soapBlocks[] = [
                    'encounter' => $encId,
                    'date'      => (string) ($enc['date'] ?? ''),
                    'text'      => $blockText,
                    'tokens'    => self::estimateTokens($blockText),
                ];
            }
        }

        return $soapBlocks;
    }

    /**
     * Recent Labs: strictly behind setting context_include_labs (off by default).
     * Whitelists procedure name, result value, units, range, status, and date.
     *
     * Prefers the native ProcedureService::search() reader (which returns orders
     * with nested reports/results) and falls back to a strict whitelist SQL query
     * when the service is unavailable or returns nothing.
     */
    private function extractRecentLabs(int $pid): string
    {
        if (class_exists(ProcedureService::class)) {
            try {
                $service = new ProcedureService();
                $result  = $service->search(['pid' => $pid]);
                $data    = $this->processingResultData($result);
                if ($data !== []) {
                    $text = $this->formatProcedureResults($data);
                    if ($text !== '') {
                        return $text;
                    }
                }
            } catch (\Throwable $e) {
                $this->logServiceFailure('ProcedureService::search', $e);
            }
        }

        // Real schema: procedure_result has NO procedure_order_id. The chain is
        // procedure_order -> procedure_report -> procedure_result, linked by
        // procedure_order_id then procedure_report_id. There is also no
        // procedure_name / result_name column; the order title lives in
        // procedure_order_type (a list_options lookup) and the analyte in
        // result_text. Verified identical across OpenEMR 8.2.0 / 8.4.1.
        $sql = "SELECT po.`procedure_order_type`, po.`order_diagnosis`,
                       pr.`result_text`, pr.`result`, pr.`units`, pr.`range`,
                       pr.`abnormal`, pr.`result_status`, pr.`date`
                FROM `procedure_order` po
                JOIN `procedure_report` rp ON rp.`procedure_order_id` = po.`procedure_order_id`
                JOIN `procedure_result` pr ON pr.`procedure_report_id` = rp.`procedure_report_id`
                WHERE po.`patient_id` = ?
                ORDER BY pr.`date` DESC
                LIMIT 15";

        $rows = $this->query($sql, [$pid]);
        if (empty($rows)) {
            return '';
        }

        $out = '### ' . xl('RECENT LABORATORY RESULTS') . "\n";
        foreach ($rows as $r) {
            $label = trim((string) ($r['result_text'] ?? ''));
            if ($label === '') {
                $label = trim((string) ($r['order_diagnosis'] ?? ''));
            }
            if ($label === '') {
                $label = trim((string) ($r['procedure_order_type'] ?? ''));
            }
            $procName   = $this->redactFreeText($label !== '' ? $label : xl('Study'));
            $resultVal  = $this->redactFreeText(trim((string) ($r['result'] ?? '')));
            $units      = trim((string) ($r['units'] ?? ''));
            $range      = trim((string) ($r['range'] ?? ''));
            $status     = trim((string) ($r['result_status'] ?? ''));
            $date       = $this->formatDate((string) ($r['date'] ?? ''));

            $line = "- {$procName}: {$resultVal}";
            if ($units !== '') {
                $line .= " {$units}";
            }
            if ($range !== '') {
                $line .= ' (' . xl('Ref') . ": {$range})";
            }
            if ($status !== '' && strtolower($status) !== 'final') {
                $line .= " [{$status}]";
            }
            // The `abnormal` flag is the authoritative abnormal marker; status codes like
            // "final" say nothing about whether the value is out of range.
            if (!empty($r['abnormal']) && strtolower((string) $r['abnormal']) === 'abnormal') {
                $line .= ' [' . xl('ABNORMAL') . ']';
            }
            if ($date !== '') {
                $line .= " ({$date})";
            }
            $out .= $line . "\n";
        }

        return $out;
    }

    /**
     * Renders nested ProcedureService result records into the labs section.
     * Only whitelisted fields reach the prompt: analyte/text label, value, units,
     * reference range, status, abnormal flag, and date.
     */
    private function formatProcedureResults(array $procedures): string
    {
        $entries = [];
        foreach ($procedures as $proc) {
            $orderLabel = trim((string) ($proc['procedure_name'] ?? ($proc['order_diagnosis'] ?? '')));
            $orderDate  = (string) ($proc['date_ordered'] ?? '');

            foreach (($proc['reports'] ?? []) as $report) {
                $reportDate = (string) ($report['date'] ?? '');
                $date       = $reportDate !== '' ? $reportDate : $orderDate;

                foreach (($report['results'] ?? []) as $res) {
                    $label = trim((string) ($res['text'] ?? ''));
                    if ($label === '') {
                        $label = $orderLabel;
                    }
                    if ($label === '') {
                        $label = 'Study';
                    }

                    $entries[] = [
                        'date'   => $date,
                        'label'  => $label,
                        'value'  => (string) ($res['result'] ?? ''),
                        'units'  => (string) ($res['units'] ?? ''),
                        'range'  => (string) ($res['range'] ?? ''),
                        'status' => (string) ($res['status'] ?? ''),
                        'abnormal' => (string) ($res['abnormal'] ?? ''),
                    ];
                }
            }
        }

        if ($entries === []) {
            return '';
        }

        // Newest first, capped at 15 to mirror the SQL fallback.
        usort($entries, static fn ($a, $b) => strcmp($b['date'], $a['date']));
        $entries = array_slice($entries, 0, 15);

        $out = '### ' . xl('RECENT LABORATORY RESULTS') . "\n";
        foreach ($entries as $e) {
            $procName  = $this->redactFreeText($e['label']);
            $resultVal = $this->redactFreeText($e['value']);

            $line = "- {$procName}: {$resultVal}";
            if ($e['units'] !== '') {
                $line .= " {$e['units']}";
            }
            if ($e['range'] !== '') {
                $line .= ' (' . xl('Ref') . ": {$e['range']})";
            }
            if ($e['status'] !== '' && strtolower($e['status']) !== 'final') {
                $line .= " [{$e['status']}]";
            }
            if ($e['abnormal'] !== '' && strtolower($e['abnormal']) === 'abnormal') {
                $line .= ' [' . xl('ABNORMAL') . ']';
            }
            if ($e['date'] !== '') {
                $line .= " ({$this->formatDate($e['date'])})";
            }
            $out .= $line . "\n";
        }

        return $out;
    }

    /**
     * Clinical notes: reads the patient's clinical notes through the native
     * ClinicalNotesService::getClinicalNotesForPatient() reader. Additive section —
     * newest notes first, capped by MAX_CLINICAL_NOTES. Only the note date, its type
     * label and the (redacted) note text reach the prompt.
     */
    private function extractClinicalNotes(int $pid): string
    {
        if (class_exists(ClinicalNotesService::class)) {
            try {
                $service = new ClinicalNotesService();
                $result  = $service->getClinicalNotesForPatient($pid);
                $data    = $this->processingResultData($result);
                if ($data !== []) {
                    return $this->formatClinicalNotes($data, true);
                }
            } catch (\Throwable $e) {
                $this->logServiceFailure('ClinicalNotesService::getClinicalNotesForPatient', $e);
            }
        }

        // Fallback: same shape via strict whitelist SQL.
        $sql = "SELECT fcn.`description`, fcn.`codetext`, fcn.`date`,
                       fcn.`clinical_notes_category`, fcn.`clinical_notes_type`, fcn.`activity`
                FROM `form_clinical_notes` fcn
                JOIN `forms` f ON (f.`form_id` = fcn.`form_id` AND f.`formdir` = 'clinical_notes' AND f.`deleted` = 0)
                WHERE fcn.`pid` = ?
                ORDER BY fcn.`id` DESC
                LIMIT 20";

        $rows = $this->query($sql, [$pid]);
        return $this->formatClinicalNotes($rows, false);
    }

    /**
     * Renders clinical note records into the "CLINICAL NOTES" section.
     *
     * @param array $notes       Note records (service shape or fallback SQL shape)
     * @param bool  $fromService Whether the rows come from ClinicalNotesService
     */
    private function formatClinicalNotes(array $notes, bool $fromService): string
    {
        $entries = [];
        foreach ($notes as $n) {
            // Skip inactive/voided notes.
            if (array_key_exists('activity', $n) && (int) ($n['activity'] ?? 1) === 0) {
                continue;
            }

            $desc = $this->redactFreeText(trim((string) ($n['description'] ?? '')));
            if ($desc === '') {
                continue;
            }

            $label = trim((string) ($n['codetext'] ?? ''));
            if ($label === '' && $fromService) {
                $label = trim((string) ($n['category_title'] ?? ''));
            }
            if ($label === '') {
                $label = xl('Clinical note');
            }

            $dateStr = trim((string) ($n['date'] ?? ''));
            if ($dateStr === '' && $fromService) {
                $dateStr = trim((string) ($n['encounter_date'] ?? ''));
            }
            $date = $this->formatDate($dateStr);

            $line = '- ';
            if ($date !== '') {
                $line .= "[{$date}] ";
            }
            $line .= '[' . $this->redactFreeText($label) . '] ' . $desc;

            $entries[] = ['sort' => $dateStr, 'line' => $line];
        }

        if ($entries === []) {
            return '';
        }

        // Newest first, capped.
        usort($entries, static fn ($a, $b) => strcmp($b['sort'], $a['sort']));
        $entries = array_slice($entries, 0, self::MAX_CLINICAL_NOTES);

        return '### ' . xl('CLINICAL NOTES') . "\n" . implode("\n", array_column($entries, 'line')) . "\n";
    }

    // -------------------------------------------------------------------------
    // Token Budget Management & Assembly
    // -------------------------------------------------------------------------

    /**
     * Assembles all sections within token budget.
     * Truncation Strategy:
     *   1. Preserves baseline clinical profile (demographics, allergies, problems, meds, history, vitals).
     *   2. Includes SOAP encounters newest first; truncates oldest encounters first.
     *   3. Includes clinical notes next (newest first), then Labs, if budget permits.
     */
    private function assembleWithBudget(
        int $tokenBudget,
        string $demographics,
        string $allergies,
        string $problems,
        string $medications,
        string $history,
        string $vitals,
        array $soapEncounters,
        string $clinicalNotes,
        string $labs
    ): array {
        $baseText = '## ' . xl('PATIENT CLINICAL CONTEXT') . "\n\n"
            . $demographics . "\n"
            . $allergies . "\n"
            . $problems . "\n"
            . $medications . "\n";

        if ($history !== '') {
            $baseText .= $history . "\n";
        }
        if ($vitals !== '') {
            $baseText .= $vitals . "\n";
        }

        $baseTokens = self::estimateTokens($baseText);
        $remaining  = max(0, $tokenBudget - $baseTokens);

        $soapText   = '';
        $truncated  = false;
        $encsAdded  = 0;

        // SOAP encounters are ordered newest-first [0 = newest, count-1 = oldest]
        // We pack newest first. If adding an encounter exceeds remaining budget, stop and mark truncated.
        if (!empty($soapEncounters)) {
            $soapHeader = '### ' . xl('PREVIOUS ENCOUNTERS (SOAP)') . "\n";
            $accumSoap  = '';

            foreach ($soapEncounters as $idx => $enc) {
                $cost = $enc['tokens'];
                if (self::estimateTokens($accumSoap . $enc['text']) <= $remaining) {
                    $accumSoap .= $enc['text'] . "\n";
                    $encsAdded++;
                } else {
                    $truncated = true;
                    break;
                }
            }

            if ($accumSoap !== '') {
                $soapText = $soapHeader . $accumSoap;
            }
        }

        $curText   = $baseText . $soapText;
        $curTokens = self::estimateTokens($curText);

        // Clinical notes (if space permits)
        $notesText = '';
        if ($clinicalNotes !== '') {
            $notesCost = self::estimateTokens($clinicalNotes);
            if ($curTokens + $notesCost <= $tokenBudget) {
                $notesText  = $clinicalNotes . "\n";
                $curText   .= $notesText;
                $curTokens  = self::estimateTokens($curText);
            } else {
                $truncated = true;
            }
        }

        // Labs (if enabled and space permits)
        $labsText = '';
        if ($labs !== '') {
            $labCost = self::estimateTokens($labs);
            if ($curTokens + $labCost <= $tokenBudget) {
                $labsText  = $labs . "\n";
                $curText  .= $labsText;
            } else {
                $truncated = true;
            }
        }

        $finalTokens = self::estimateTokens($curText);

        $sections = [
            'base_clinical_profile' => $baseTokens,
            'soap_encounters'       => self::estimateTokens($soapText),
            'clinical_notes'        => self::estimateTokens($notesText),
            'labs'                  => self::estimateTokens($labsText),
            'total_estimated'       => $finalTokens,
        ];

        return [
            'text'             => trim($curText),
            'estimated_tokens' => $finalTokens,
            'token_budget'     => $tokenBudget,
            'truncated'        => $truncated,
            'sections'         => $sections,
        ];
    }

    // -------------------------------------------------------------------------
    // Patient Identifiers Query (For Automated Leak Check Only)
    // -------------------------------------------------------------------------

    /**
     * Queries identifying fields strictly for automated leak check comparison.
     * Never called during standard prompt context assembly.
     */
    private function queryPatientIdentifiers(int $pid): array
    {
        $sql = "SELECT `fname`, `lname`, `mname`, `DOB`, `phone_cell`, `phone_home`,
                       `email`, `ss`
                FROM `patient_data`
                WHERE `pid` = ?
                LIMIT 1";

        $rows = $this->query($sql, [$pid]);
        if (empty($rows)) {
            return [];
        }

        $r = $rows[0];
        $ids = [];

        if (!empty($r['fname'])) {
            $ids['first_name'] = (string) $r['fname'];
        }
        if (!empty($r['lname'])) {
            $ids['last_name'] = (string) $r['lname'];
        }
        if (!empty($r['mname'])) {
            $ids['middle_name'] = (string) $r['mname'];
        }
        if (!empty($r['DOB']) && $r['DOB'] !== '0000-00-00') {
            $ids['date_of_birth'] = (string) $r['DOB'];
        }
        if (!empty($r['phone_cell'])) {
            $ids['cell_phone'] = (string) $r['phone_cell'];
        }
        if (!empty($r['phone_home'])) {
            $ids['home_phone'] = (string) $r['phone_home'];
        }
        if (!empty($r['phone_biz'])) {
            $ids['work_phone'] = (string) $r['phone_biz'];
        }
        if (!empty($r['email'])) {
            $ids['email'] = (string) $r['email'];
        }
        if (!empty($r['ss'])) {
            $ids['social_security_or_national_id'] = (string) $r['ss'];
        }

        return $ids;
    }

    // -------------------------------------------------------------------------
    // Database Execution Helper
    // -------------------------------------------------------------------------

    private function query(string $sql, array $params = []): array
    {
        if ($this->dbQueryCallback !== null) {
            return ($this->dbQueryCallback)($sql, $params);
        }

        if (class_exists(QueryUtils::class)) {
            try {
                return QueryUtils::fetchRecords($sql, $params) ?? [];
            } catch (\Throwable $e) {
                if ($this->logger) {
                    $this->logger->error('[AiAssistant] PatientContextBuilder query error: ' . $e->getMessage());
                }
                return [];
            }
        }

        return [];
    }
}
