<?php

/**
 * Module configuration page entry point.
 *
 * This file is rendered inside an iframe by the OpenEMR Module Manager when
 * the administrator clicks "Configure" on the module. It delegates all
 * rendering and security checks to SettingsController.
 *
 * Security: session + admin ACL enforced inside SettingsController.
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// $sessionAllowWrite must be set before globals.php to allow session writes.
$sessionAllowWrite = true;
require_once __DIR__ . '/../../../globals.php';

use OpenEMR\Modules\AiAssistant\Controller\SettingsController;

$controller = new SettingsController();
$controller->dispatch();
