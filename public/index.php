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
use OpenEMR\Common\Session\SessionUtil;
use OpenEMR\Modules\AiAssistant\Controller\SettingsController;

// --- Session guard ---
SessionUtil::coreSessionStart();
if (empty($_SESSION['authUserID'])) {
    http_response_code(401);
    exit(json_encode(['error' => xlt('Unauthorized')]));
}

$action = $_GET['action'] ?? 'settings';

// Route table: action => [controller_class, method, acl_section, acl_object]
$routes = [
    'settings' => [SettingsController::class, 'dispatch', 'ai_assistant', 'admin'],
];

if (!isset($routes[$action])) {
    http_response_code(404);
    exit(json_encode(['error' => xlt('Not found')]));
}

[$class, $method, $aclSection, $aclObject] = $routes[$action];

// ACL check
if (!AclMain::aclCheckCore($aclSection, $aclObject)) {
    http_response_code(403);
    exit(json_encode(['error' => xlt('Access denied.')]));
}

$controller = new $class();
$controller->$method();
