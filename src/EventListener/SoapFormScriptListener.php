<?php

/**
 * SoapFormScriptListener — injects the AI Dictation JS into the SOAP form.
 *
 * How the SOAP form is served in OpenEMR 8.2.0 and 8.4.1
 * -------------------------------------------------------
 * NEW encounter note:
 *   GET /interface/patient_file/encounter/load_form.php?formname=soap&pid=..&encounter=..
 *   ScriptFilterEvent pageName = "load_form.php"
 *   SCRIPT_NAME       = ".../encounter/load_form.php"
 *
 * VIEW / EDIT saved note:
 *   GET /interface/patient_file/encounter/view_form.php?formname=soap&id=N&..
 *   ScriptFilterEvent pageName = "view_form.php"
 *   SCRIPT_NAME       = ".../encounter/view_form.php"
 *
 * Both scripts hard-code $pageName = "new.php" / "view.php" locally and pass
 * them to FormLocator::findFile() which require_once's the individual form file.
 * The HTTP entry point is always load_form.php or view_form.php, so
 * $_SERVER['SCRIPT_NAME'] (and therefore ScriptFilterEvent::getPageName())
 * always reflects the entry script, NOT the individual form file.
 *
 * Match strategy: pageName IN {load_form.php, view_form.php}
 *                 AND $_GET['formname'] === 'soap'   (validated, never trusted further)
 *
 * Compatibility: OpenEMR 8.2.0 and 8.4.1 — confirmed by direct source inspection.
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\AiAssistant\EventListener;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Events\Core\ScriptFilterEvent;
use OpenEMR\Modules\AiAssistant\Settings\SettingsManager;

class SoapFormScriptListener
{
    /**
     * The entry scripts that frame the SOAP form in both 8.2.0 and 8.4.1.
     * The ScriptFilterEvent pageName equals basename($_SERVER['SCRIPT_NAME']).
     */
    private const SOAP_ENTRY_PAGES = ['load_form.php', 'view_form.php'];

    /**
     * The exact value of $_GET['formname'] that identifies the SOAP form.
     * Any other value → do not inject.
     */
    private const SOAP_FORMNAME = 'soap';

    private SystemLogger $logger;
    private SettingsManager $settings;

    public function __construct()
    {
        $this->logger   = new SystemLogger();
        $this->settings = new SettingsManager();
    }

    /**
     * Called for every ScriptFilterEvent (once per page during setupHeader()).
     */
    public function onScriptFilter(ScriptFilterEvent $event): void
    {
        $pageName = $event->getPageName();
        $formName = $this->safeGetFormname();

        // Check 1: Must be the SOAP form page (new or view)
        if (!$this->isSoapFormPage($pageName, $formName)) {
            return;
        }

        // Check 2: User must hold the 'use' permission
        if (!AclMain::aclCheckCore('ai_assistant', 'use')) {
            return;
        }

        // Check 3: Module must be configured (consent acknowledged, active provider key saved)
        if (!$this->settings->isConfigured()) {
            return;
        }

        $scriptUrl = $this->buildAssetUrl('public/assets/js/ai-dictation.js');

        $scripts = $event->getScripts();
        if (in_array($scriptUrl, $scripts, true)) {
            return;
        }

        $scripts[] = $scriptUrl;
        $event->setScripts($scripts);

        if ($this->isDebugEnabled()) {
            $this->logger->debug(
                '[AiAssistant] Script injected'
                . ' | url=' . $scriptUrl
                . ' | pageName=' . $pageName
                . ' | formname=' . $formName
            );
        }
    }

    /**
     * Called for every StyleFilterEvent to inject module CSS into the SOAP form.
     */
    public function onStyleFilter(\OpenEMR\Events\Core\StyleFilterEvent $event): void
    {
        $pageName = $event->getPageName();
        $formName = $this->safeGetFormname();

        if (!$this->isSoapFormPage($pageName, $formName)) {
            return;
        }

        if (!AclMain::aclCheckCore('ai_assistant', 'use')) {
            return;
        }

        if (!$this->settings->isConfigured()) {
            return;
        }

        $styleUrl = $this->buildAssetUrl('public/assets/css/ai-assistant.css');
        $styles   = $event->getStyles();
        if (!in_array($styleUrl, $styles, true)) {
            $styles[] = $styleUrl;
            $event->setStyles($styles);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Builds an asset URL with a cache-busting query parameter derived from the
     * file's last modification time on disk (falling back to module version).
     *
     * OpenEMR's Header::createElement() detects the '?' and appends '&v=...',
     * ensuring that our modification timestamp is preserved and changes on every edit.
     */
    private function buildAssetUrl(string $relativeAssetPath): string
    {
        $webRoot   = OEGlobalsBag::getInstance()->getWebRoot();
        $cleanPath = ltrim($relativeAssetPath, '/');
        $diskPath  = dirname(__DIR__, 2) . '/' . $cleanPath;

        $version = file_exists($diskPath) ? (string) filemtime($diskPath) : $this->getModuleVersion();

        return $webRoot
            . '/interface/modules/custom_modules/oe-module-ai-assistant/'
            . $cleanPath
            . '?mtime=' . $version;
    }

    /**
     * Reads the module version from version.php as a fallback.
     */
    private function getModuleVersion(): string
    {
        $versionFile = dirname(__DIR__, 2) . '/version.php';
        if (file_exists($versionFile)) {
            include $versionFile;
            if (isset($v_module_major, $v_module_minor, $v_module_patch)) {
                return "{$v_module_major}.{$v_module_minor}.{$v_module_patch}";
            }
        }
        return '0.1.0';
    }

    /**
     * Returns true when the current page is the SOAP form framed inside either
     * load_form.php (new note) or view_form.php (existing saved note), AND the
     * formname GET parameter is exactly "soap".
     *
     * pageName is basename($_SERVER['SCRIPT_NAME']) as set by Header::setupHeader().
     * formname is validated via filter_input; do not use it for anything other
     * than this routing decision.
     */
    private function isSoapFormPage(string $pageName, string $formName): bool
    {
        return in_array($pageName, self::SOAP_ENTRY_PAGES, true)
            && $formName === self::SOAP_FORMNAME;
    }

    /**
     * Reads $_GET['formname'] safely for routing purposes only.
     *
     * Returns an empty string if the parameter is absent or contains characters
     * other than alphanumeric and underscore (the valid form directory name charset).
     * The return value is NEVER used for file access, SQL, or output.
     */
    private function safeGetFormname(): string
    {
        $raw = filter_input(INPUT_GET, 'formname', FILTER_UNSAFE_RAW) ?? '';
        // Allow only the characters that OpenEMR permits in form directory names
        // (check_file_dir_name uses [a-zA-Z0-9_-] effectively).
        if (!is_string($raw) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $raw)) {
            return '';
        }
        return $raw;
    }

    /**
     * Checks if debug logging is explicitly enabled in settings.
     */
    private function isDebugEnabled(): bool
    {
        try {
            return $this->settings->get('debug_log_content', '0') === '1';
        } catch (\Throwable $e) {
            return false;
        }
    }
}