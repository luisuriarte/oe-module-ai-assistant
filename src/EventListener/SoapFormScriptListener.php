<?php

/**
 * SoapFormScriptListener — injects the AI Dictation JS into the SOAP form.
 *
 * Listens to ScriptFilterEvent. When the SOAP new/view form is rendered,
 * appends our ai-dictation.js to the page <head> via setupHeader().
 *
 * Compatibility: OpenEMR 8.2.0+ (ScriptFilterEvent identical in 8.2 and 8.4).
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\AiAssistant\EventListener;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Events\Core\ScriptFilterEvent;

class SoapFormScriptListener
{
    private SystemLogger $logger;

    public function __construct()
    {
        $this->logger = new SystemLogger();
    }

    /**
     * Called when ScriptFilterEvent fires (once per page, during setupHeader()).
     *
     * Detects SOAP form pages using the full script path stored in the event
     * context argument — more reliable than basename alone.
     */
    public function onScriptFilter(ScriptFilterEvent $event): void
    {
        $pageName   = $event->getPageName();
        $scriptName = $event->getContextArgument(
            ScriptFilterEvent::CONTEXT_ARGUMENT_SCRIPT_NAME
        ) ?? '';

        $matched = $this->isSoapFormPage($scriptName);

        // DIAG-3: log every event received, regardless of match
        $this->logger->error(
            '[AiAssistant DIAG-3] ScriptFilterEvent received'
            . ' | pageName=' . $pageName
            . ' | scriptName=' . $scriptName
            . ' | matched=' . ($matched ? 'YES' : 'NO')
        );

        if (!$matched) {
            return;
        }

        $webRoot    = OEGlobalsBag::getInstance()->getWebRoot();
        $scriptUrl  = $webRoot
            . '/interface/modules/custom_modules/oe-module-ai-assistant/public/assets/js/ai-dictation.js';

        $scripts   = $event->getScripts();
        $scripts[] = $scriptUrl;
        $event->setScripts($scripts);

        // DIAG-4: log the script URL that was actually injected
        $this->logger->error(
            '[AiAssistant DIAG-4] Script injected'
            . ' | url=' . $scriptUrl
            . ' | pageName=' . $pageName
            . ' | scriptName=' . $scriptName
        );
    }

    /**
     * Returns true if the current page is the SOAP form (new or view action).
     *
     * Matching is performed on the FULL server-side script path stored in
     * CONTEXT_ARGUMENT_SCRIPT_NAME — not on the basename — to avoid false
     * positives from other forms also named new.php or view.php.
     *
     * In OpenEMR 8.2.0/8.4.1, Header::setupHeader() sets:
     *   pageName  = basename($_SERVER['SCRIPT_NAME'])   → "new.php" or "view.php"
     *   scriptName = $_SERVER['SCRIPT_NAME']            → "/interface/forms/soap/new.php"
     */
    private function isSoapFormPage(string $scriptName): bool
    {
        return str_contains($scriptName, 'forms/soap/new.php')
            || str_contains($scriptName, 'forms/soap/view.php');
    }
}