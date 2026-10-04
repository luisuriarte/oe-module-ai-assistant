<?php

/**
 * SettingsController — renders and processes the module admin settings page.
 *
 * Security checklist (enforced on every request):
 *   1. Valid OpenEMR session (checked by moduleConfig.php / index.php before calling us)
 *   2. ACL: ai_assistant / admin
 *   3. CSRF token on all POST requests
 *   4. API key values never sent back to the browser
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\AiAssistant\Controller;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\AiAssistant\Audit\AuditLogger;
use OpenEMR\Modules\AiAssistant\Provider\ProviderFactory;
use OpenEMR\Modules\AiAssistant\Settings\SettingsManager;
use OpenEMR\Modules\AiAssistant\Transcription\TranscriptionClient;

class SettingsController
{
    private SettingsManager $settings;
    private SystemLogger $logger;

    public function __construct()
    {
        $this->settings = new SettingsManager();
        $this->logger   = new SystemLogger();
    }

    /**
     * Main dispatch: enforces ACL, handles GET (render form) and POST (save).
     */
    public function dispatch(): void
    {
        // ACL guard — must have ai_assistant / admin permission
        if (!AclMain::aclCheckCore('ai_assistant', 'admin')) {
            http_response_code(403);
            echo xlt('Access denied.');
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handlePost();
        } else {
            $this->renderForm();
        }
    }

    // -------------------------------------------------------------------------
    // POST handler
    // -------------------------------------------------------------------------

    private function handlePost(): void
    {
        // CSRF validation
        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '', $session)) {
            http_response_code(400);
            echo xlt('Invalid CSRF token.');
            return;
        }

        $saved    = [];
        $errors   = [];
        $defaults = $this->settings->getDefaults();

        // Process consent flag first — required before any other setting can be saved
        if (!empty($_POST['consent_acknowledged'])) {
            $this->settings->acknowledgeConsent();
        }

        // Save each expected key from POST, skipping encrypted keys if blank
        // (blank = "do not change existing key")
        $encryptedKeys = ['openai_api_key', 'anthropic_api_key', 'gemini_api_key'];

        foreach (array_keys($defaults) as $key) {
            if ($key === 'consent_acknowledged') {
                continue; // handled above
            }

            $postValue = $_POST[$key] ?? null;

            if ($postValue === null) {
                continue;
            }

            // For encrypted keys: skip if empty (user didn't change it)
            if (in_array($key, $encryptedKeys, true) && trim($postValue) === '') {
                continue;
            }

            try {
                $this->settings->set($key, $postValue);
                $saved[] = $key;
            } catch (\Throwable $e) {
                $this->logger->error('[AiAssistant] settings save error', [
                    'key'   => $key,
                    'error' => $e->getMessage(),
                ]);
                $errors[] = $key;
            }
        }

        // Re-render form with a status message
        $this->renderForm(
            success: empty($errors),
            message: empty($errors)
                ? xlt('Settings saved.')
                : xlt('Some settings could not be saved. Check server logs.')
        );
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    private function renderForm(bool $success = true, string $message = ''): void
    {
        $session  = SessionWrapperFactory::getInstance()->getActiveSession();
        $csrf     = CsrfUtils::collectCsrfToken($session);
        $current  = $this->settings->getAll();
        $defaults = $this->settings->getDefaults();
        $webRoot  = OEGlobalsBag::getInstance()->getWebRoot();

        // Merge defaults so the form always has values on first run
        foreach ($defaults as $k => $v) {
            if (!array_key_exists($k, $current)) {
                $current[$k] = $v;
            }
        }

        // Encrypted key status (show "Key saved" instead of the key value)
        $keyStatus = [
            'openai_api_key'    => $this->settings->hasEncryptedValue('openai_api_key'),
            'anthropic_api_key' => $this->settings->hasEncryptedValue('anthropic_api_key'),
            'gemini_api_key'    => $this->settings->hasEncryptedValue('gemini_api_key'),
        ];

        $consentGiven = $this->settings->isConsentGiven();
        $siteId       = $session->get('site_id') ?? $_SESSION['site_id'] ?? ($GLOBALS['site_id'] ?? 'default');

        // Include the settings template
        $templatePath = __DIR__ . '/../../templates/settings.php';
        include $templatePath;
    }

    /**
     * AJAX endpoint: tests connection to the Whisper transcription server.
     * Enforces ai_assistant/admin ACL and CSRF token.
     */
    public function testWhisper(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!AclMain::aclCheckCore('ai_assistant', 'admin')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => xlt('Access denied.')]);
            return;
        }

        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        $token   = $_POST['csrf_token_form'] ?? $_POST['csrf_token'] ?? '';
        if (!CsrfUtils::verifyCsrfToken($token, $session)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => xlt('Invalid CSRF token.')]);
            return;
        }

        $rawUrl = trim((string) ($_POST['whisper_url'] ?? ''));
        if ($rawUrl === '') {
            $rawUrl = $this->settings->get('whisper_url', 'http://127.0.0.1:8178');
        }

        try {
            $client = new TranscriptionClient($rawUrl, 5);
            $result = $client->testConnection(4);
            http_response_code(200);
            echo json_encode($result);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * AJAX endpoint: tests connection to an AI provider (e.g. Gemini models.list).
     * Enforces ai_assistant/admin ACL and CSRF token. Consumes 0 tokens.
     */
    public function testProvider(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!AclMain::aclCheckCore('ai_assistant', 'admin')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => xlt('Access denied.')]);
            return;
        }

        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        $token   = $_POST['csrf_token_form'] ?? $_POST['csrf_token'] ?? '';
        if (!CsrfUtils::verifyCsrfToken($token, $session)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => xlt('Invalid CSRF token.')]);
            return;
        }

        $provider = trim((string) ($_POST['provider'] ?? 'gemini'));
        $apiKey   = trim((string) ($_POST['api_key'] ?? ''));

        try {
            $factory = new ProviderFactory($this->settings);
            $adapter = $factory->create($provider, $apiKey !== '' ? $apiKey : null);
            $result  = $adapter->testConnection();
            http_response_code($result['ok'] ? 200 : 400);
            echo json_encode($result);
        } catch (\Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * AJAX endpoint: runs a synthetic test prompt through the real provider adapter.
     * Enforces ai_assistant/admin ACL and CSRF token.
     * Audited with patient_id = 0, encounter_id = 0, and records token usage.
     */
    public function adminTestPrompt(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!AclMain::aclCheckCore('ai_assistant', 'admin')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => xlt('Access denied.')]);
            return;
        }

        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        $token   = $_POST['csrf_token_form'] ?? $_POST['csrf_token'] ?? '';
        if (!CsrfUtils::verifyCsrfToken($token, $session)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => xlt('Invalid CSRF token.')]);
            return;
        }

        $userId = (int) (
            $session->get('authUserID')
            ?? $session->get('authId')
            ?? $_SESSION['authUserID']
            ?? $_SESSION['authId']
            ?? 1
        );

        $provider = trim((string) ($_POST['provider'] ?? ''));
        $prompt   = trim((string) ($_POST['prompt'] ?? ''));
        if ($prompt === '') {
            $prompt = 'Hello, this is a test prompt from OpenEMR AI Assistant.';
        }

        $systemPrompt = trim((string) ($_POST['system_prompt'] ?? 'You are a helpful clinical assistant. Provide brief, concise responses.'));

        $messages = [];
        if ($systemPrompt !== '') {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $start   = microtime(true);
        $factory = new ProviderFactory($this->settings);

        try {
            $adapter  = $factory->create($provider ?: null);
            $response = $adapter->generate($messages, [
                'max_tokens'  => (int) ($_POST['max_tokens'] ?? 256),
                'temperature' => (float) ($_POST['temperature'] ?? 0.2),
            ]);
            $durationMs = (int) round((microtime(true) - $start) * 1000);

            // Audit record: patient_id = 0, encounter_id = 0, action = 'test_prompt'
            $audit = new AuditLogger();
            $audit->log(
                userId: $userId,
                patientId: 0,
                encounterId: 0,
                action: 'test_prompt',
                provider: $adapter->getProviderName(),
                model: $response->model,
                status: 'ok',
                errorCode: '',
                durationMs: $durationMs,
                tokensIn: $response->tokensIn,
                tokensOut: $response->tokensOut
            );

            http_response_code(200);
            echo json_encode([
                'ok'           => true,
                'provider'     => $adapter->getProviderName(),
                'model'        => $response->model,
                'text'         => $response->text,
                'tokens_in'    => $response->tokensIn,
                'tokens_out'   => $response->tokensOut,
                'total_tokens' => $response->getTotalTokens(),
                'duration_ms'  => $durationMs,
            ]);
        } catch (\Throwable $e) {
            $durationMs = (int) round((microtime(true) - $start) * 1000);
            $audit = new AuditLogger();
            $audit->log(
                userId: $userId,
                patientId: 0,
                encounterId: 0,
                action: 'test_prompt',
                provider: $provider ?: $this->settings->getActiveProvider(),
                model: '',
                status: 'error',
                errorCode: substr((new \ReflectionClass($e))->getShortName(), 0, 64),
                durationMs: $durationMs,
                tokensIn: 0,
                tokensOut: 0
            );

            http_response_code(400);
            echo json_encode([
                'ok'         => false,
                'error'      => $e->getMessage(),
                'error_type' => (new \ReflectionClass($e))->getShortName(),
            ]);
        }
    }

    /**
     * AJAX endpoint: generates patient context and runs automated leak check.
     * Enforces ai_assistant/admin ACL, patient access ACL (patients/med or patients/demo),
     * and CSRF token.
     * Audited with patient_id, duration, and leak check outcome WITHOUT logging any clinical text.
     */
    public function previewContext(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!AclMain::aclCheckCore('ai_assistant', 'admin')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => xlt('Access denied.')]);
            return;
        }

        // Verify native patient-level access
        if (!AclMain::aclCheckCore('patients', 'med') && !AclMain::aclCheckCore('patients', 'demo')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => xlt('Patient access denied.')]);
            return;
        }

        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        $token   = $_POST['csrf_token_form'] ?? $_POST['csrf_token'] ?? '';
        if (!CsrfUtils::verifyCsrfToken($token, $session)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => xlt('Invalid CSRF token.')]);
            return;
        }

        $userId = (int) (
            $session->get('authUserID')
            ?? $session->get('authId')
            ?? $_SESSION['authUserID']
            ?? $_SESSION['authId']
            ?? 1
        );

        $pid = (int) ($_POST['pid'] ?? 0);
        if ($pid <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => xlt('Invalid patient ID.')]);
            return;
        }

        $start   = microtime(true);
        $builder = new \OpenEMR\Modules\AiAssistant\Context\PatientContextBuilder($this->settings);

        ob_start();
        try {
            $contextResult = $builder->buildContext($pid);
            $contextText   = $contextResult['text'];
            $leakCheck     = $builder->leakCheck($contextText, $pid);
            $durationMs    = (int) round((microtime(true) - $start) * 1000);
            $noise         = ob_get_clean();

            if ($noise !== '' && (str_contains($noise, 'SQL Statement Error') || str_contains($noise, 'Fatal error'))) {
                http_response_code(500);
                echo json_encode([
                    'ok'    => false,
                    'error' => trim(strip_tags($noise)),
                ]);
                return;
            }

            // Audit record: patient_id is logged, but NO CLINICAL CONTENT is saved.
            $audit = new AuditLogger();
            $audit->log(
                userId: $userId,
                patientId: $pid,
                encounterId: 0,
                action: 'preview_context',
                provider: '',
                model: '',
                status: $leakCheck['pass'] ? 'ok' : 'leak_detected',
                errorCode: $leakCheck['pass'] ? '' : 'LEAK_DETECTED',
                durationMs: $durationMs,
                tokensIn: 0,
                tokensOut: 0
            );

            http_response_code(200);
            echo json_encode([
                'ok'               => true,
                'pid'              => $pid,
                'context'          => $contextText,
                'estimated_tokens' => $contextResult['estimated_tokens'],
                'truncated'        => $contextResult['truncated'],
                'leak_check'       => $leakCheck,
                'duration_ms'      => $durationMs,
            ]);
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            $durationMs = (int) round((microtime(true) - $start) * 1000);
            $audit = new AuditLogger();
            $audit->log(
                userId: $userId,
                patientId: $pid,
                encounterId: 0,
                action: 'preview_context',
                provider: '',
                model: '',
                status: 'error',
                errorCode: substr((new \ReflectionClass($e))->getShortName(), 0, 64),
                durationMs: $durationMs,
                tokensIn: 0,
                tokensOut: 0
            );

            http_response_code(400);
            echo json_encode([
                'ok'    => false,
                'error' => $e->getMessage(),
            ]);
        }
    }
}


