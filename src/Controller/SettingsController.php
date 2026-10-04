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
}

