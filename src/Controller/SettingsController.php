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
use OpenEMR\Modules\AiAssistant\Session\CsrfCompat;
use OpenEMR\Modules\AiAssistant\Session\SessionAccessor;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\AiAssistant\Audit\AuditLogger;
use OpenEMR\Modules\AiAssistant\Provider\ProviderFactory;
use OpenEMR\Modules\AiAssistant\Settings\SettingsManager;
use OpenEMR\Modules\AiAssistant\Security\ConsentGate;
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

    /**
     * Reports the consent gate state for the SOAP toolbar.
     *
     * The toolbar JS calls this on init to decide whether to enable the microphone
     * controls. It is NOT a security boundary: every transmitting endpoint re-checks the
     * gate server-side via ConsentGate before building any payload.
     *
     * Deliberately returns no PHI, no provider names and no model names — only the gate
     * verdict and a translatable explanation, so the response is safe to log.
     */
    public function moduleStatus(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!AclMain::aclCheckCore('ai_assistant', 'use') && !AclMain::aclCheckCore('ai_assistant', 'admin')) {
            http_response_code(403);
            echo json_encode([
                'ok'      => false,
                'allowed' => false,
                'reason'  => 'access_denied',
                'message' => xlt('Access denied.'),
            ]);
            return;
        }

        $gate     = new ConsentGate($this->settings);
        $allowed  = $gate->isTransmissionAllowed();
        $reason   = $allowed ? '' : $gate->denialReason();
        $message  = $allowed ? '' : $gate->denialMessage();

        // Only 'consent_acknowledged' is reported; provider_not_configured deliberately
        // does not leak which provider is active or whether its key exists.
        echo json_encode([
            'ok'      => true,
            'allowed' => $allowed,
            'reason'  => $reason,
            'message' => $message,
            // Recording ceiling derived from the admin setting, so the client timer and
            // its "limit reached" text track whisper_max_audio_sec instead of a hardcoded
            // 3 minutes that silently disagreed with the server-side validation.
            'max_audio_sec' => (int) $this->settings->get('whisper_max_audio_sec', 180),
            'i18n'          => self::dictationStrings(),
            // Layer 2 is optional: the chat widget stays hidden unless an admin enabled it.
            // Chat strings ride the same endpoint for the same reason dictation strings
            // do - a static JS file cannot be given an inline config object.
            'chat_enabled'  => (string) $this->settings->get('chat_enabled', '0') === '1',
            'chat_i18n'     => self::chatStrings(),
        ]);
    }

    /**
     * Server-side translations for the dictation toolbar.
     *
     * public/assets/js/ai-dictation.js is a static file with no PHP, and
     * ScriptFilterEvent cannot carry an inline config object (setScripts() runs every
     * URL through ModulesApplication::filterSafeLocalModuleFiles()). So the strings are
     * shipped over this endpoint, which the toolbar already calls on init.
     *
     * Keys are stable snake_case identifiers; values are xlt() output for the active
     * language. Payload only — no settings, no provider, no PHI.
     *
     * @return array<string, string>
     */
    private static function dictationStrings(): array
    {
        return [
            // Toolbar
            'title'                 => xlt('Clinical AI Dictation'),
            'record'                => xlt('Record'),
            'stop'                  => xlt('Stop'),
            'discard'               => xlt('Discard'),
            'generate_soap'         => xlt('Generate SOAP Note'),
            'ready'                 => xlt('Ready to dictate'),
            'transcript_label'      => xlt('Dictation transcript (you can edit it before generating the draft):'),
            'transcript_placeholder' => xlt('The consultation transcript will appear here...'),

            // Recording / upload status
            'recording'             => xlt('Recording consultation...'),
            'audio_limit_reached'   => xlt('Audio limit reached (%d min). Processing...'),
            'audio_recorded'        => xlt('Audio recorded. Uploading...'),
            'recording_discarded'   => xlt('Recording discarded.'),
            'sending_audio'         => xlt('Uploading audio to the Whisper server...'),
            'busy_retry'            => xlt('Server busy. Retrying upload in 3 s...'),
            'upload_error'          => xlt('Upload error: '),
            'transcribing'          => xlt('Transcribing audio (Whisper)...'),
            'upload_network_error'  => xlt('Network error uploading audio: '),
            'busy_waiting'          => xlt('Transcription server busy. Waiting in queue...'),
            'job_expired'           => xlt('The transcription expired or does not exist. Record again.'),
            'poll_auth_error'       => xlt('Authorization error checking the transcription: '),
            'processing'            => xlt('Processing transcription...'),
            'transcription_error'   => xlt('Transcription error: '),
            'transcription_done'    => xlt('Transcription complete.'),
            'poll_status_error'     => xlt('Error checking status: '),

            // Draft generation
            'no_transcript'         => xlt('No transcription available to generate the draft.'),
            'generating_draft'      => xlt('Generating SOAP draft with AI...'),
            'draft_ok'              => xlt('Draft generated successfully (%s - %s tokens).'),
            'draft_error'           => xlt('Error generating draft: '),
            'invalid_response'      => xlt('Invalid response'),
            'draft_alert_failed'    => xlt('Could not generate the draft: '),
            'provider_error'        => xlt('Provider error'),
            'connection_error'      => xlt('Connection error: '),
            'connect_failed'        => xlt('Could not connect to the server: '),
            'ai_draft_marker'       => xlt('AI draft'),
            'verify_badge'          => xlt('Contains items to verify [VERIFY]'),
            'review_banner'         => xlt('AI-generated draft: review and edit the content before saving the encounter.'),
            'understood'            => xlt('Got it'),
            'prior_content_title'   => xlt('Previous content detected'),
            'prior_content_body'    => xlt('The SOAP note fields already contain text. How would you like to incorporate the new AI-generated draft?'),
            'cancel'                => xlt('Cancel'),
            'append_end'            => xlt('Append at the end'),
            'replace_all'           => xlt('Replace everything'),
            'verify_save_confirm'   => xlt("Warning: the SOAP note still contains pending [VERIFY: ...] markers.\n\nSave anyway?"),

            // Consent gate / microphone
            'gate_blocked'          => xlt('AI dictation is not enabled on this server. Contact your administrator.'),
            'gate_blocked_short'    => xlt('AI dictation is not enabled on this server.'),
            'gate_unknown'          => xlt('Could not verify whether AI dictation is enabled.'),
            'no_recording_support'  => xlt('Audio recording is not supported in this browser.'),
            'mic_denied'            => xlt('Microphone permission denied. Please allow microphone access in the browser to dictate.'),
            'mic_not_found'         => xlt('No microphone found connected to this device.'),
            'mic_error'             => xlt('Error accessing the microphone: '),

            // Transcribe error codes (code => human text)
            'err_invalid_csrf'              => xlt('Invalid CSRF token. Reload the page.'),
            'err_unauthorized'              => xlt('Unauthenticated or expired session.'),
            'err_access_denied'             => xlt('You do not have permission to use AI dictation.'),
            'err_invalid_clinical_context'  => xlt('The patient or encounter could not be validated.'),
            'err_clinical_context_mismatch' => xlt('The job does not belong to this patient or encounter.'),
            'err_no_audio_file'             => xlt('No audio file was received.'),
            'err_invalid_upload'            => xlt('Invalid audio file.'),
            'err_file_too_large'            => xlt('The audio exceeds the maximum allowed size.'),
            'err_unsupported_audio_format'  => xlt('Unsupported audio format.'),
            'err_invalid_whisper_configuration' => xlt('The Whisper URL is invalid or unreachable.'),
            'err_storage_error'             => xlt('Server storage error.'),
            'err_busy'                      => xlt('The transcription engine is busy. Try again in a few seconds.'),
            'err_worker_timeout'            => xlt('The transcription exceeded the maximum allowed time.'),
            'err_worker_exception'          => xlt('Internal server error during transcription.'),
            'err_transcription_failed'      => xlt('Transcription failed.'),
            'err_worker_error'              => xlt('Error in the transcription process.'),
            'err_job_not_found'             => xlt('The transcription job expired or does not exist.'),
            'err_unknown'                   => xlt('Unknown failure'),
        ];
    }
    /**
     * Server-side translations for the Layer 2 chat panel.
     *
     * Delivered through the same ?action=module_status endpoint as the dictation strings,
     * and under a separate key so the two toolbars can be fetched together without one
     * overwriting the other. Payload only - no settings, no provider, no PHI.
     *
     * @return array<string, string>
     */
    private static function chatStrings(): array
    {
        return [
            'title'         => xlt('Clinical AI Chat'),
            'toggle'        => xlt('Patient chat'),
            'open'          => xlt('Open patient chat'),
            'close'         => xlt('Close'),
            'placeholder'   => xlt('Ask a question about this patient...'),
            'send'          => xlt('Send'),
            'thinking'      => xlt('Reviewing the chart...'),
            'empty_state'   => xlt('Answers come only from the chart context and cite the section they came from.'),
            'sources'       => xlt('Sources'),
            'gate_blocked'  => xlt('AI chat is not enabled on this server. Contact your administrator.'),
            'gate_unknown'  => xlt('Could not verify whether AI chat is enabled.'),
            'disabled'      => xlt('The patient chat panel is disabled. An administrator must enable it in AI Assistant Settings.'),
            'empty_question' => xlt('Type a question first.'),
            'too_long'      => xlt('The question exceeds the maximum allowed length.'),
            'network_error' => xlt('Connection error. Please try again.'),
            'chat_error'    => xlt('The question could not be answered. Please try again.'),
            'clear'         => xlt('Clear conversation'),
            'cleared'       => xlt('Conversation cleared.'),
        ];
    }

    // -------------------------------------------------------------------------
    // POST handler
    // -------------------------------------------------------------------------

    private function handlePost(): void
    {
        // CSRF validation
        $session = SessionAccessor::resolve();
        // Fail closed: without a usable session the CSRF token cannot be verified.
        if ($session === null) {
            http_response_code(400);
            echo xlt('Invalid CSRF token.');
            return;
        }
        if (!CsrfCompat::verify((string) ($_POST['csrf_token_form'] ?? ''), $session)) {
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
        $encryptedKeys = ['openai_api_key', 'anthropic_api_key', 'gemini_api_key', 'grok_api_key'];

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
        $session  = SessionAccessor::resolve();
        $csrf     = CsrfCompat::collect($session);
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
            'grok_api_key'      => $this->settings->hasEncryptedValue('grok_api_key'),
        ];

        $consentGiven = $this->settings->isConsentGiven();
        $siteId       = $session->get('site_id') ?? $_SESSION['site_id'] ?? ($GLOBALS['site_id'] ?? 'default');

        // Count of audit inserts that could not be written, so a broken audit trail is
        // visible from the admin UI instead of failing silently.
        $auditFailures = AuditLogger::failureCount();

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

        $session = SessionAccessor::resolve();
        // Fail closed: without a usable session the CSRF token cannot be verified.
        if ($session === null) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_csrf']);
            return;
        }
        $token   = $_POST['csrf_token_form'] ?? $_POST['csrf_token'] ?? '';
        if (!CsrfCompat::verify((string) $token, $session)) {
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

        $session = SessionAccessor::resolve();
        // Fail closed: without a usable session the CSRF token cannot be verified.
        if ($session === null) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_csrf']);
            return;
        }
        $token   = $_POST['csrf_token_form'] ?? $_POST['csrf_token'] ?? '';
        if (!CsrfCompat::verify((string) $token, $session)) {
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

        $session = SessionAccessor::resolve();
        // Fail closed: without a usable session the CSRF token cannot be verified.
        if ($session === null) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_csrf']);
            return;
        }
        $token   = $_POST['csrf_token_form'] ?? $_POST['csrf_token'] ?? '';
        if (!CsrfCompat::verify((string) $token, $session)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => xlt('Invalid CSRF token.')]);
            return;
        }

        $userId = SessionAccessor::currentUserId();
        if ($userId === null) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => xlt('Not authenticated.')]);
            return;
        }

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

        $session = SessionAccessor::resolve();
        // Fail closed: without a usable session the CSRF token cannot be verified.
        if ($session === null) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_csrf']);
            return;
        }
        $token   = $_POST['csrf_token_form'] ?? $_POST['csrf_token'] ?? '';
        if (!CsrfCompat::verify((string) $token, $session)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => xlt('Invalid CSRF token.')]);
            return;
        }

        $userId = SessionAccessor::currentUserId();
        if ($userId === null) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => xlt('Not authenticated.')]);
            return;
        }

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


