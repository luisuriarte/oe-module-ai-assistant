<?php

/**
 * SoapAiFormController — modern SOAP-AI editor (Layer 1) and its save endpoint.
 *
 * This is the "nuevo formulario SOAP-AI" that the clinician opens from the native
 * SOAP form's "IA" button. It is intentionally NOT registered as its own encounter
 * form entry: it is an alternate editor for the same note.
 *
 * Storage contract (identical to the native SOAP form, C_FormSOAP):
 *   - form_soap         : id, date, pid, activity, subjective, objective, assessment, plan
 *   - forms (formdir)   : inserted with formdir = 'soap' via FormService::addForm()
 *
 * Writing formdir = 'soap' keeps the note a first-class SOAP note for the encounter
 * summary, reports, e-signature, the REST API (EncounterService filters
 * fo.formdir = 'soap') and every third-party integration.
 *
 * Security checklist:
 *   - Active OpenEMR session.
 *   - ACL ai_assistant/use (admin override) + patients/med|demo.
 *   - CSRF on save.
 *   - Server-side validation that the encounter belongs to the patient, that the
 *     note (when editing) belongs to that patient AND encounter, and encounter
 *     sensitivity ACL.
 *   - Metadata-only audit logging.
 *
 * Compatibility: OpenEMR 8.2.0+ (PHP 8.2 compatible).
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Controller;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\AiAssistant\Audit\AuditLogger;
use OpenEMR\Modules\AiAssistant\Session\CsrfCompat;
use OpenEMR\Modules\AiAssistant\Session\SessionAccessor;
use OpenEMR\Modules\AiAssistant\Settings\SettingsManager;
use OpenEMR\Services\FormService;

class SoapAiFormController
{
    private const MAX_FIELD_CHARS = 65535;

    private SystemLogger $logger;

    public function __construct()
    {
        $this->logger = new SystemLogger();
    }

    /**
     * HTML entry point (public/form.php). Renders the modern editor for the note
     * identified by ?id (0 = new note) for the session's opened patient/encounter.
     */
    public function render(): void
    {
        $session = SessionAccessor::resolve();
        if ($session === null) {
            $this->deny(401, xlt('Unauthenticated or expired session.'));
            return;
        }

        if (!AclMain::aclCheckCore('ai_assistant', 'use') && !AclMain::aclCheckCore('ai_assistant', 'admin')) {
            $this->deny(403, xlt('Access denied: module use permission required.'));
            return;
        }

        if (!AclMain::aclCheckCore('patients', 'med') && !AclMain::aclCheckCore('patients', 'demo')) {
            $this->deny(403, xlt('Patient access denied.'));
            return;
        }

        $pid       = $this->sessionInt($session, 'pid');
        $encounter = $this->sessionInt($session, 'encounter');

        // Fallbacks: pid is also a GET parameter on the native form URL.
        if ($pid <= 0) {
            $pid = (int) ($_GET['pid'] ?? 0);
        }
        if ($encounter <= 0) {
            $encounter = (int) ($_GET['encounter'] ?? 0);
        }
        if ($encounter <= 0) {
            $encounter = (int) ($GLOBALS['encounter'] ?? 0);
        }

        $formId = (int) ($_GET['id'] ?? 0);

        if ($pid <= 0 || $encounter <= 0 || !$this->validateClinicalContext($pid, $encounter)) {
            $this->deny(400, xlt('The patient or encounter could not be validated.'));
            return;
        }

        if (!$this->sensitivityAllowed($encounter)) {
            $this->deny(403, xlt('Access denied: You do not have permission to access this sensitive encounter.'));
            return;
        }

        $subjective = '';
        $objective  = '';
        $assessment = '';
        $plan       = '';

        if ($formId > 0) {
            $row = $this->fetchSoapForEncounter($formId, $pid, $encounter);
            if ($row === null) {
                $this->deny(404, xlt('The SOAP note could not be found.'));
                return;
            }
            $subjective = (string) ($row['subjective'] ?? '');
            $objective  = (string) ($row['objective'] ?? '');
            $assessment = (string) ($row['assessment'] ?? '');
            $plan       = (string) ($row['plan'] ?? '');
        }

        $webRoot        = OEGlobalsBag::getInstance()->getWebRoot();
        $publicEndpoint = $webRoot . '/interface/modules/custom_modules/oe-module-ai-assistant/public/index.php';
        $siteId         = (string) ($session->get('site_id') ?? ($_SESSION['site_id'] ?? 'default'));
        $csrf           = CsrfCompat::collect($session);

        $settings    = new SettingsManager();
        $chatEnabled = (string) $settings->get('chat_enabled', '0') === '1';

        // Native view the editor returns to after saving (and the "back" target).
        $nativeViewUrl = $webRoot
            . '/interface/patient_file/encounter/view_form.php?formname=soap&id=' . $formId
            . '&site=' . rawurlencode($siteId);

        $assets = [
            'sharedCss' => $this->assetUrl('public/assets/css/ai-assistant.css', $webRoot),
            'editorCss' => $this->assetUrl('public/assets/css/soap-ai.css', $webRoot),
            'editorJs'  => $this->assetUrl('public/assets/js/soap-ai.js', $webRoot),
        ];

        include dirname(__DIR__, 2) . '/templates/soap_ai.php';
    }

    /**
     * AJAX action: 'soap_ai_save'.
     * Persists the four SOAP fields into form_soap (+ forms for a new note) and
     * returns the native view URL to redirect to.
     */
    public function save(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
            return;
        }

        if (!AclMain::aclCheckCore('ai_assistant', 'use') && !AclMain::aclCheckCore('ai_assistant', 'admin')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => xlt('Access denied: module use permission required.')]);
            return;
        }

        if (!AclMain::aclCheckCore('patients', 'med') && !AclMain::aclCheckCore('patients', 'demo')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => xlt('Patient access denied.')]);
            return;
        }

        $session = SessionAccessor::resolve();
        if ($session === null) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'invalid_csrf']);
            return;
        }

        $token = (string) ($_POST['csrf_token_form'] ?? $_POST['csrf_token'] ?? '');
        if (!CsrfCompat::verify($token, $session)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'invalid_csrf']);
            return;
        }

        $userId = SessionAccessor::currentUserId();
        if ($userId === null) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => xlt('Not authenticated.')]);
            return;
        }

        $pid       = (int) ($_POST['pid'] ?? 0);
        $encounter = (int) ($_POST['encounter'] ?? 0);
        $formId    = (int) ($_POST['id'] ?? 0);

        if ($pid <= 0 || $encounter <= 0 || !$this->validateClinicalContext($pid, $encounter)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'invalid_clinical_context']);
            return;
        }

        if (!$this->sensitivityAllowed($encounter)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => xlt('Access denied: You do not have permission to access this sensitive encounter.')]);
            return;
        }

        $subjective = $this->cleanField($_POST['subjective'] ?? '');
        $objective  = $this->cleanField($_POST['objective'] ?? '');
        $assessment = $this->cleanField($_POST['assessment'] ?? '');
        $plan       = $this->cleanField($_POST['plan'] ?? '');

        $webRoot = OEGlobalsBag::getInstance()->getWebRoot();
        $siteId  = (string) ($session->get('site_id') ?? ($_SESSION['site_id'] ?? 'default'));

        try {
            if ($formId > 0) {
                // Editing: the note must belong to this patient AND this encounter.
                if ($this->fetchSoapForEncounter($formId, $pid, $encounter) === null) {
                    http_response_code(404);
                    echo json_encode(['ok' => false, 'error' => 'note_not_found']);
                    return;
                }

                QueryUtils::sqlStatementThrowException(
                    'UPDATE `form_soap` SET `subjective` = ?, `objective` = ?, `assessment` = ?, `plan` = ? '
                    . 'WHERE `id` = ? AND `pid` = ?',
                    [$subjective, $objective, $assessment, $plan, $formId, $pid]
                );
            } else {
                // New note: same row shape the native C_FormSOAP::default_action_process() creates.
                $formId = (int) QueryUtils::sqlInsert(
                    'INSERT INTO `form_soap` (`date`, `pid`, `activity`, `subjective`, `objective`, `assessment`, `plan`) '
                    . 'VALUES (NOW(), ?, 1, ?, ?, ?, ?)',
                    [$pid, $subjective, $objective, $assessment, $plan]
                );

                if ($formId <= 0) {
                    throw new \RuntimeException('form_soap insert returned no id');
                }

                // forms row: formdir stays 'soap' (Option B — single source of truth).
                $authorized = ((string) ($session->get('userauthorized') ?? '0') === '1') ? '1' : '0';
                (new FormService())->addForm($encounter, 'SOAP', $formId, 'soap', $pid, $authorized);
            }

            $this->audit($userId, $pid, $encounter, 'ok', '', $formId);

            $redirect = $webRoot
                . '/interface/patient_file/encounter/view_form.php?formname=soap&id=' . $formId
                . '&site=' . rawurlencode($siteId);

            http_response_code(200);
            echo json_encode([
                'ok'       => true,
                'id'       => $formId,
                'redirect' => $redirect,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[AiAssistant] soap_ai_save failed: ' . $e->getMessage());
            $this->audit($userId, $pid, $encounter, 'error', 'save_failed', $formId);

            http_response_code(500);
            echo json_encode([
                'ok'    => false,
                'error' => xlt('Could not save the SOAP note. Please try again.'),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Loads the note only when it belongs to the given patient and encounter.
     *
     * form_soap has no encounter column, so the binding is proven through forms.form_id.
     * A note from another encounter of the same patient must not be editable from here.
     *
     * @return array<string, mixed>|null
     */
    private function fetchSoapForEncounter(int $formId, int $pid, int $encounter): ?array
    {
        try {
            $rows = QueryUtils::fetchRecords(
                'SELECT fs.`id`, fs.`subjective`, fs.`objective`, fs.`assessment`, fs.`plan` '
                . 'FROM `form_soap` fs '
                . 'JOIN `forms` fo ON fo.`form_id` = fs.`id` AND fo.`formdir` = ? '
                . 'WHERE fs.`id` = ? AND fs.`pid` = ? AND fo.`encounter` = ? AND fo.`deleted` = 0 '
                . 'LIMIT 1',
                ['soap', $formId, $pid, $encounter]
            );
        } catch (\Throwable $e) {
            $this->logger->error('[AiAssistant] soap lookup failed: ' . $e->getMessage());
            return null;
        }

        return !empty($rows) ? $rows[0] : null;
    }

    private function validateClinicalContext(int $pid, int $encounter): bool
    {
        try {
            $patient = QueryUtils::fetchRecords(
                'SELECT `pid` FROM `patient_data` WHERE `pid` = ? LIMIT 1',
                [$pid]
            );
            if (empty($patient)) {
                return false;
            }

            $enc = QueryUtils::fetchRecords(
                'SELECT `encounter` FROM `form_encounter` WHERE `pid` = ? AND `encounter` = ? LIMIT 1',
                [$pid, $encounter]
            );
            return !empty($enc);
        } catch (\Throwable $e) {
            $this->logger->error('[AiAssistant] clinical context check failed: ' . $e->getMessage());
            return false;
        }
    }

    private function sensitivityAllowed(int $encounter): bool
    {
        try {
            $rows = QueryUtils::fetchRecords(
                'SELECT `sensitivity` FROM `form_encounter` WHERE `encounter` = ? LIMIT 1',
                [$encounter]
            );
            if (empty($rows)) {
                return true;
            }

            $sensitivity = trim((string) ($rows[0]['sensitivity'] ?? 'normal'));
            if ($sensitivity === '' || $sensitivity === 'normal') {
                return true;
            }

            return (bool) AclMain::aclCheckCore('sensitivities', $sensitivity);
        } catch (\Throwable $e) {
            $this->logger->error('[AiAssistant] sensitivity check failed: ' . $e->getMessage());
            // Fail closed: an unreadable sensitivity value must not grant access.
            return false;
        }
    }

    /** Normalises a free-text SOAP field: NUL-strip, trim outer space, bound length. */
    private function cleanField(mixed $value): string
    {
        $text = trim((string) $value, " \t\n\r\0\x0B");
        if (str_contains($text, "\0")) {
            $text = str_replace("\0", '', $text);
        }
        if (mb_strlen($text) > self::MAX_FIELD_CHARS) {
            $text = mb_substr($text, 0, self::MAX_FIELD_CHARS);
        }
        return $text;
    }

    private function sessionInt(object $session, string $key): int
    {
        try {
            return (int) ($session->get($key) ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Builds a cache-busted URL for a module asset relative to the module root.
     */
    private function assetUrl(string $relativePath, string $webRoot): string
    {
        $clean = ltrim($relativePath, '/');
        $disk  = dirname(__DIR__, 2) . '/' . $clean;
        $v     = file_exists($disk) ? (string) filemtime($disk) : '1';

        return $webRoot
            . '/interface/modules/custom_modules/oe-module-ai-assistant/'
            . $clean
            . '?v=' . $v;
    }

    /**
     * Metadata-only audit row (never the clinical text).
     */
    private function audit(int $userId, int $pid, int $encounter, string $status, string $errorCode, int $formId): void
    {
        try {
            (new AuditLogger())->log(
                userId: $userId,
                patientId: $pid,
                encounterId: $encounter,
                action: 'soap_ai_save',
                provider: '',
                model: '',
                status: $status,
                errorCode: $errorCode,
                durationMs: 0,
                tokensIn: 0,
                tokensOut: 0
            );
        } catch (\Throwable $e) {
            $this->logger->error('[AiAssistant] soap_ai_save audit failed: ' . $e->getMessage());
        }
    }

    private function deny(int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . text(xl('AI Assistant')) . '</title></head>'
            . '<body style="font-family:sans-serif;padding:2rem;">'
            . '<p>' . text($message) . '</p>'
            . '</body></html>';
    }
}
