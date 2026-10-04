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
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\AiAssistant\Controller\SettingsController;
use OpenEMR\Modules\AiAssistant\Controller\TranscribeController;

// --- Session guard ---
$session = SessionWrapperFactory::getInstance()->getActiveSession();
$userId  = (int) (
    $session->get('authUserID')
    ?? $session->get('authId')
    ?? $_SESSION['authUserID']
    ?? $_SESSION['authId']
    ?? 0
);
$authUser = (string) ($session->get('authUser') ?? $_SESSION['authUser'] ?? '');

if ($userId <= 0 && $authUser === '') {
    http_response_code(401);
    exit(json_encode(['error' => xlt('Unauthorized')]));
}

if (empty($_SESSION['authUserID']) && $userId > 0) {
    $_SESSION['authUserID'] = $userId;
}

$action = $_GET['action'] ?? 'settings';

// Route table: action => [controller_class, method, acl_section, acl_object]
$routes = [
    'settings'          => [SettingsController::class, 'dispatch', 'ai_assistant', 'admin'],
    'test_whisper'      => [SettingsController::class, 'testWhisper', 'ai_assistant', 'admin'],
    'transcribe_submit' => [TranscribeController::class, 'submit', 'ai_assistant', 'use'],
    'transcribe_status' => [TranscribeController::class, 'status', 'ai_assistant', 'use'],
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
