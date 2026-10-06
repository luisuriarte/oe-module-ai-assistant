<?php

/**
 * AI Assistant module public front controller.
 *
 * Routes authenticated requests to the appropriate controller action.
 * Every route requires:
 *   1. A valid OpenEMR session
 *   2. A valid CSRF token (for POST requests)
 *   3. The appropriate ACL permission
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

$sessionAllowWrite = true;
require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Modules\AiAssistant\Session\SessionAccessor;
use OpenEMR\Modules\AiAssistant\Controller\ChatController;
use OpenEMR\Modules\AiAssistant\Controller\DraftController;
use OpenEMR\Modules\AiAssistant\Controller\SettingsController;
use OpenEMR\Modules\AiAssistant\Controller\TranscribeController;

// --- Session guard ---
$session = SessionAccessor::resolve();
// Fail closed: with no usable session there is no authenticated user.
if ($session === null) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}
$userId = SessionAccessor::currentUserId();

// Fail closed: without a resolved user id there is no authenticated identity to bind
// ACLs, audit records or job ownership to. A username alone is not sufficient.
if ($userId === null) {
    http_response_code(401);
    exit(json_encode(['error' => xlt('Unauthorized')]));
}

if (empty($_SESSION['authUserID'])) {
    $_SESSION['authUserID'] = $userId;
}

$action = $_GET['action'] ?? 'settings';

// Route table: action => [controller_class, method, acl_section, acl_object]
$routes = [
    'settings'          => [SettingsController::class, 'dispatch', 'ai_assistant', 'admin'],
    'test_whisper'      => [SettingsController::class, 'testWhisper', 'ai_assistant', 'admin'],
    'test_provider'     => [SettingsController::class, 'testProvider', 'ai_assistant', 'admin'],
    'admin_test_prompt' => [SettingsController::class, 'adminTestPrompt', 'ai_assistant', 'admin'],
    'preview_context'   => [SettingsController::class, 'previewContext', 'ai_assistant', 'admin'],
    'module_status'     => [SettingsController::class, 'moduleStatus', 'ai_assistant', 'use'],
    'transcribe_submit' => [TranscribeController::class, 'submit', 'ai_assistant', 'use'],
    'transcribe_status' => [TranscribeController::class, 'status', 'ai_assistant', 'use'],
    'soap_draft'        => [DraftController::class, 'createDraft', 'ai_assistant', 'use'],
    'chat'              => [ChatController::class, 'send', 'ai_assistant', 'use'],
];

if (!isset($routes[$action])) {
    http_response_code(404);
    exit(json_encode(['error' => xlt('Not found')]));
}

[$class, $method, $aclSection, $aclObject] = $routes[$action];

// ACL check: allows 'admin' as an override for 'use' routes
if (!AclMain::aclCheckCore($aclSection, $aclObject)) {
    if ($aclObject === 'use' && AclMain::aclCheckCore($aclSection, 'admin')) {
        // admin permitted
    } else {
        http_response_code(403);
        exit(json_encode(['error' => xlt('Access denied.')]));
    }
}

$controller = new $class();
$controller->$method();
