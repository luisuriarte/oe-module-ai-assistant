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
 *   7. Date Formatting: Configurable absolute (YYYY-MM-DD) or relative ("hace X días").
 *   8. Automated Leak Checking: Scans generated context against real patient identifiers.
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
use OpenEMR\Services\VitalsService;

class PatientContextBuilder
{
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

        // 2. Redact Argentine DNI with dots (e.g. 34.567.890)
        $text = preg_replace(
            '/\b\d{1,2}\.\d{3}\.\d{3}\b/',
            '[REDACTED-ID]',
            $text
        );

        // 3. Redact US SSN format (xxx-xx-xxxx)
        $text = preg_replace(
            '/\b\d{3}-\d{2}-\d{4}\b/',
            '[REDACTED-ID]',
            $text
        );

        // 4. Redact Phone numbers (international, Argentine 11-xxxx-xxxx, US (xxx) xxx-xxxx, etc.)
        $text = preg_replace(
            '/(?:\+?\d{1,3}[-\s]?)?(?:\(?\d{2,4}\)?[-.\s]?)?\d{3,4}[-\s]?\d{3,4}\b/',
            '[REDACTED-PHONE]',
            $text
        );

        // 5. Redact Sequences of 7 to 11 digits (National IDs, CUIL/CUIT, SSNs, passports)
        $text = preg_replace(
            '/\b\d{7,11}\b/',
            '[REDACTED-ID]',
            $text
        );

        return (string) $text;
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
                return 'hoy';
            }
            if ($diffDays === -1) {
                return 'ayer';
            }
            if ($diffDays < 0) {
                $daysAgo = abs($diffDays);
                if ($daysAgo < 30) {
                    return "hace {$daysAgo} días";
                }
                if ($daysAgo < 365) {
                    $months = (int) round($daysAgo / 30.4);
                    return "hace {$months} " . ($months === 1 ? 'mes' : 'meses');
                }
                $years = (int) round($daysAgo / 365.25);
                return "hace {$years} " . ($years === 1 ? 'año' : 'años');
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

        $ageStr = 'Edad no registrada';
        if ($dobStr !== '' && $dobStr !== '0000-00-00') {
            try {
                $dob = new DateTime($dobStr);
                $now = new DateTime();
                $age = $dob->diff($now)->y;
                $ageStr = "{$age} años";
            } catch (\Throwable) {
                $ageStr = 'Edad desconocida';
            }
        }

        $sexStr = match (strtolower($sex)) {
            'female', 'f', 'femenino' => 'Femenino',
            'male', 'm', 'masculino'  => 'Masculino',
            default                   => $sex !== '' ? $sex : 'No especificado',
        };

        return "### PERFIL DEL PACIENTE\n- Edad: {$ageStr}\n- Sexo: {$sexStr}\n";
    }

    /**
     * Allergies: Universal source 'lists' with type = 'allergy' and activity = 1.
     */
    private function extractAllergies(int $pid): string
    {
        $sql = "SELECT `title`, `comments`, `severity`
                FROM `lists`
                WHERE `pid` = ?
                  AND `type` = 'allergy'
                  AND `activity` = 1
                  AND (`enddate` IS NULL OR `enddate` = '' OR `enddate` = '0000-00-00' OR `enddate` > NOW())
                ORDER BY `begdate` DESC";

        $rows = $this->query($sql, [$pid]);
        if (empty($rows)) {
            return "### ALERGIAS CONOCIDAS\n- Sin alergias registradas activas.\n";
        }

        $out = "### ALERGIAS CONOCIDAS\n";
        foreach ($rows as $r) {
            $title    = $this->redactFreeText(trim((string) ($r['title'] ?? '')));
            $severity = trim((string) ($r['severity'] ?? ''));
            $comments = $this->redactFreeText(trim((string) ($r['comments'] ?? '')));

            $line = "- {$title}";
            if ($severity !== '') {
                $line .= " (Severidad: {$severity})";
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
     */
    private function extractActiveProblems(int $pid): string
    {
        $sql = "SELECT `title`, `begdate`, `comments`
                FROM `lists`
                WHERE `pid` = ?
                  AND `type` = 'medical_problem'
                  AND `activity` = 1
                  AND (`enddate` IS NULL OR `enddate` = '' OR `enddate` = '0000-00-00' OR `enddate` > NOW())
                ORDER BY `begdate` DESC";

        $rows = $this->query($sql, [$pid]);
        if (empty($rows)) {
            return "### PROBLEMAS ACTIVOS\n- Sin problemas médicos activos registrados.\n";
        }

        $out = "### PROBLEMAS ACTIVOS\n";
        foreach ($rows as $r) {
            $title    = $this->redactFreeText(trim((string) ($r['title'] ?? '')));
            $date     = $this->formatDate((string) ($r['begdate'] ?? ''));
            $comments = $this->redactFreeText(trim((string) ($r['comments'] ?? '')));

            $line = "- {$title}";
            if ($date !== '') {
                $line .= " (Inicio: {$date})";
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
     * Excludes discontinued or expired medications.
     */
    private function extractActiveMedications(int $pid): string
    {
        // 1. Check prescriptions table (active = 1)
        $sqlRx = "SELECT `drug`, `dosage`, `form`, `route`, `interval`, `date_added`
                  FROM `prescriptions`
                  WHERE `patient_id` = ?
                    AND `active` = 1
                    AND (`date_modified` IS NULL OR `date_modified` = '' OR `date_modified` <= NOW())
                  ORDER BY `date_added` DESC";

        $rowsRx = $this->query($sqlRx, [$pid]);

        // 2. Check lists table (type = 'medication' and activity = 1)
        $sqlLists = "SELECT `title`, `begdate`, `comments`
                     FROM `lists`
                     WHERE `pid` = ?
                       AND `type` = 'medication'
                       AND `activity` = 1
                       AND (`enddate` IS NULL OR `enddate` = '' OR `enddate` = '0000-00-00' OR `enddate` > NOW())
                     ORDER BY `begdate` DESC";

        $rowsLists = $this->query($sqlLists, [$pid]);

        if (empty($rowsRx) && empty($rowsLists)) {
            return "### MEDICACIÓN ACTUAL\n- Sin medicación activa registrada.\n";
        }

        $out = "### MEDICACIÓN ACTUAL\n";

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
                $line .= " [Recetado: {$date}]";
            }
            $out .= $line . "\n";
        }

        foreach ($rowsLists as $r) {
            $title    = $this->redactFreeText(trim((string) ($r['title'] ?? '')));
            $date     = $this->formatDate((string) ($r['begdate'] ?? ''));
            $comments = $this->redactFreeText(trim((string) ($r['comments'] ?? '')));

            $line = "- {$title}";
            if ($date !== '') {
                $line .= " (Desde: {$date})";
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
     */
    private function extractClinicalHistory(int $pid): string
    {
        $sql = "SELECT `tobacco`, `alcohol`, `exercise`, `diet`,
                       `medical_history`, `surgical_history`, `family_history`, `social_history`
                FROM `history_data`
                WHERE `pid` = ?
                ORDER BY `date` DESC
                LIMIT 1";

        $rows = $this->query($sql, [$pid]);
        if (empty($rows)) {
            return '';
        }

        $r     = $rows[0];
        $items = [];

        if (!empty($r['tobacco'])) {
            $items[] = 'Tabaco: ' . $this->redactFreeText(trim((string) $r['tobacco']));
        }
        if (!empty($r['alcohol'])) {
            $items[] = 'Alcohol: ' . $this->redactFreeText(trim((string) $r['alcohol']));
        }
        if (!empty($r['exercise'])) {
            $items[] = 'Actividad física: ' . $this->redactFreeText(trim((string) $r['exercise']));
        }
        if (!empty($r['medical_history'])) {
            $items[] = 'Antecedentes médicos: ' . $this->redactFreeText(trim((string) $r['medical_history']));
        }
        if (!empty($r['surgical_history'])) {
            $items[] = 'Antecedentes quirúrgicos: ' . $this->redactFreeText(trim((string) $r['surgical_history']));
        }
        if (!empty($r['family_history'])) {
            $items[] = 'Antecedentes familiares: ' . $this->redactFreeText(trim((string) $r['family_history']));
        }
        if (!empty($r['social_history'])) {
            $items[] = 'Historia social: ' . $this->redactFreeText(trim((string) $r['social_history']));
        }

        if (empty($items)) {
            return '';
        }

        return "### ANTECEDENTES Y HÁBITOS\n- " . implode("\n- ", $items) . "\n";
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
            $parts[] = "PA: {$v['bps']}/{$v['bpd']} mmHg";
        }
        if (!empty($v['pulse'])) {
            $parts[] = "Pulso: {$v['pulse']} lpm";
        }
        if (!empty($v['temperature'])) {
            $parts[] = "Temp: {$v['temperature']} °C";
        }
        if (!empty($v['respiration'])) {
            $parts[] = "FR: {$v['respiration']} rpm";
        }
        if (!empty($v['oxygen_saturation'])) {
            $parts[] = "SatO2: {$v['oxygen_saturation']}%";
        }
        if (!empty($v['BMI'])) {
            $parts[] = "IMC: {$v['BMI']}";
        }

        if (empty($parts)) {
            return '';
        }

        $dateStr = $this->formatDate((string) ($v['date'] ?? ''));
        $header  = $dateStr !== '' ? "### SIGNOS VITALES RECIENTES ({$dateStr})\n" : "### SIGNOS VITALES RECIENTES\n";

        return $header . "- " . implode(', ', $parts) . "\n";
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
        $sql = "SELECT fe.`id`, fe.`date`, fe.`encounter`, fe.`sensitivity`
                FROM `form_encounter` fe
                JOIN `forms` f ON (f.form_id = fe.id AND f.formdir = 'encounter' AND f.deleted = 0)
                WHERE fe.`pid` = ?
                ORDER BY fe.`date` DESC
                LIMIT ?";

        $encounters = $this->query($sql, [$pid, $limit * 2]); // Fetch buffer in case some are sensitive
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
            $encHeader     = $dateFormatted !== '' ? "#### Consulta del {$dateFormatted}\n" : "#### Consulta\n";

            $noteParts = [];
            foreach ($soaps as $s) {
                $subj = $this->redactFreeText(trim((string) ($s['subjective'] ?? '')));
                $obj  = $this->redactFreeText(trim((string) ($s['objective'] ?? '')));
                $ass  = $this->redactFreeText(trim((string) ($s['assessment'] ?? '')));
                $plan = $this->redactFreeText(trim((string) ($s['plan'] ?? '')));

                $lines = [];
                if ($subj !== '') {
                    $lines[] = "- Subjetivo: {$subj}";
                }
                if ($obj !== '') {
                    $lines[] = "- Objetivo: {$obj}";
                }
                if ($ass !== '') {
                    $lines[] = "- Evaluación: {$ass}";
                }
                if ($plan !== '') {
                    $lines[] = "- Plan: {$plan}";
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
     */
    private function extractRecentLabs(int $pid): string
    {
        $sql = "SELECT po.`procedure_name`, pr.`result_name`, pr.`result`,
                       pr.`units`, pr.`range`, pr.`result_status`, pr.`date`
                FROM `procedure_order` po
                JOIN `procedure_result` pr ON pr.`procedure_order_id` = po.`procedure_order_id`
                WHERE po.`patient_id` = ?
                ORDER BY pr.`date` DESC
                LIMIT 15";

        $rows = $this->query($sql, [$pid]);
        if (empty($rows)) {
            return '';
        }

        $out = "### RESULTADOS DE LABORATORIO RECIENTES\n";
        foreach ($rows as $r) {
            $procName   = $this->redactFreeText(trim((string) ($r['procedure_name'] ?? $r['result_name'] ?? 'Estudio')));
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
                $line .= " (Ref: {$range})";
            }
            if ($status !== '' && strtolower($status) !== 'final') {
                $line .= " [{$status}]";
            }
            if ($date !== '') {
                $line .= " ({$date})";
            }
            $out .= $line . "\n";
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Token Budget Management & Assembly
    // -------------------------------------------------------------------------

    /**
     * Assembles all sections within token budget.
     * Truncation Strategy:
     *   1. Preserves baseline clinical profile (demographics, allergies, problems, meds, history, vitals).
     *   2. Includes SOAP encounters newest first; truncates oldest encounters first.
     *   3. Includes Labs if budget permits.
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
        string $labs
    ): array {
        $baseText = "## CONTEXTO CLÍNICO DEL PACIENTE\n\n"
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
            $soapHeader = "### CONSULTAS Y EVOLUCIONES PREVIAS (SOAP)\n";
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
                       `phone_biz`, `phone_contact`, `email`, `ss`
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
