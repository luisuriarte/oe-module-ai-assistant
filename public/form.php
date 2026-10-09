<?php

/**
 * SOAP-AI editor entry point.
 *
 * Renders the modern SOAP editor (Layer 1 dictation + Layer 2 chat) for the
 * session's opened patient/encounter. Opened from the "IA" button injected into
 * the native SOAP form (see SoapFormScriptListener + ai-launch.js).
 *
 * This file is intentionally a page, not a JSON endpoint: it emits HTML through
 * Header::setupHeader(). All security checks (session, ACL, patient access,
 * encounter ownership, sensitivity) live in SoapAiFormController::render().
 *
 * Reads/writes the SAME storage as the native SOAP form: form_soap + forms
 * (formdir='soap'). The save endpoint is index.php?action=soap_ai_save.
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// $sessionAllowWrite must be set before globals.php to allow session writes.
$sessionAllowWrite = true;
require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Modules\AiAssistant\Controller\SoapAiFormController;

$controller = new SoapAiFormController();
$controller->render();
