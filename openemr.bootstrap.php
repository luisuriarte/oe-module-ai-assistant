<?php

/**
 * AI Assistant module bootstrap.
 *
 * Executed by the OpenEMR Module Manager on every page load when the module is
 * enabled. Receives three injected globals from ModulesApplication::loadCustomModule():
 *   - $eventDispatcher  EventDispatcherInterface
 *   - $classLoader      ModulesClassLoader
 *   - $module           array (DB row from modules table)
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

use OpenEMR\Core\ModulesClassLoader;
use OpenEMR\Events\Core\ScriptFilterEvent;
use OpenEMR\Events\Core\StyleFilterEvent;
use OpenEMR\Modules\AiAssistant\EventListener\SoapFormScriptListener;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @var ModulesClassLoader       $classLoader
 * @var EventDispatcherInterface $eventDispatcher
 * @var array                    $module
 */

// Register PSR-4 namespace so autoloader resolves OpenEMR\Modules\AiAssistant\*
$classLoader->registerNamespaceIfNotExists(
    'OpenEMR\\Modules\\AiAssistant\\',
    __DIR__ . DIRECTORY_SEPARATOR . 'src'
);

// Register event listeners.
// SoapFormScriptListener injects our JS & CSS into the SOAP form <head>.
$soapListener = new SoapFormScriptListener();
$eventDispatcher->addListener(
    ScriptFilterEvent::EVENT_NAME,
    [$soapListener, 'onScriptFilter']
);
$eventDispatcher->addListener(
    StyleFilterEvent::EVENT_NAME,
    [$soapListener, 'onStyleFilter']
);