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

use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Events\Core\ScriptFilterEvent;

class SoapFormScriptListener
{
    /**
     * Called when ScriptFilterEvent fires (once per page, during setupHeader()).
     *
     * Detects SOAP form pages using the full script path stored in the event
     * context argument — more reliable than basename alone.
     */
    public function onScriptFilter(ScriptFilterEvent $event): void
    {
        $scriptName = $event->getContextArgument(
            ScriptFilterEvent::CONTEXT_ARGUMENT_SCRIPT_NAME
        ) ?? '';

        // Match both new.php (create) and view.php (edit existing encounter)
        if (!$this->isSoapFormPage($scriptName)) {
            return;
        }

        $webRoot = OEGlobalsBag::getInstance()->getWebRoot();
        $moduleBase = $webRoot
            . '/interface/modules/custom_modules/oe-module-ai-assistant/public/assets/js';

        $scripts = $event->getScripts();
        $scripts[] = $moduleBase . '/ai-dictation.js';
        $event->setScripts($scripts);
    }

    /**
     * Returns true if the current page is the SOAP form (new or view action).
     *
     * Uses str_contains() on the full server path — avoids false positives from
     * basename matching when other forms happen to be named new.php or view.php.
     */
    private function isSoapFormPage(string $scriptName): bool
    {
        return str_contains($scriptName, 'forms/soap/new.php')
            || str_contains($scriptName, 'forms/soap/view.php');
    }
}
