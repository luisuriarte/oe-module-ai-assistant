<?php

/**
 * ModuleManagerListener — handles Module Manager lifecycle events.
 *
 * Called by the Laminas Module Manager when the module is installed, enabled,
 * disabled or unregistered. Performs table creation, ACL registration and cleanup.
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

use OpenEMR\Common\Acl\AclExtended;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Core\AbstractModuleActionListener;

class ModuleManagerListener extends AbstractModuleActionListener
{
    private SystemLogger $logger;

    public function __construct()
    {
        parent::__construct();
        $this->logger = new SystemLogger();
    }

    /**
     * Required by AbstractModuleActionListener. Dispatches to private methods
     * that match the action name (install, enable, disable, unregister, …).
     */
    public function moduleManagerAction(
        $methodName,
        $modId,
        string $currentActionStatus = 'Success'
    ): string {
        if (method_exists(self::class, $methodName)) {
            return self::$methodName($modId, $currentActionStatus);
        }
        return $currentActionStatus;
    }

    /**
     * Required: returns this module's PSR-4 namespace so Module Manager can
     * register it during lifecycle actions.
     */
    public static function getModuleNamespace(): string
    {
        return 'OpenEMR\\Modules\\AiAssistant\\';
    }

    /**
     * Required: returns a fresh instance of this listener.
     */
    public static function initListenerSelf(): ModuleManagerListener
    {
        return new self();
    }

    // -------------------------------------------------------------------------
    // Lifecycle methods
    // -------------------------------------------------------------------------

    /**
     * Called when the module is first registered/installed.
     * Creates database tables and registers ACL sections.
     */
    private function install($modId, string $currentActionStatus): string
    {
        try {
            $this->runSqlFile(__DIR__ . '/sql/install.sql');
            $this->registerAclSections();
        } catch (\Throwable $e) {
            $this->logger->error(
                '[AiAssistant] install failed: ' . $e->getMessage()
            );
            return 'Error: ' . $e->getMessage();
        }
        return $currentActionStatus;
    }

    /**
     * Called when the module is enabled. No-op for now; settings are managed
     * through the settings page.
     */
    private function enable($modId, string $currentActionStatus): string
    {
        return $currentActionStatus;
    }

    /**
     * Called when the module is disabled. Removes event listeners automatically
     * because the bootstrap file is no longer loaded; nothing extra needed here.
     */
    private function disable($modId, string $currentActionStatus): string
    {
        return $currentActionStatus;
    }

    /**
     * Called when the module is unregistered (full removal).
     * Drops all module tables so nothing is left behind.
     */
    private function unregister($modId, string $currentActionStatus): string
    {
        try {
            $this->runSqlFile(__DIR__ . '/sql/uninstall.sql');
        } catch (\Throwable $e) {
            $this->logger->error(
                '[AiAssistant] unregister failed: ' . $e->getMessage()
            );
            return 'Error: ' . $e->getMessage();
        }
        return $currentActionStatus;
    }

    /** Called after SQL install file is run by Module Manager — no extra work. */
    private function install_sql($modId, string $currentActionStatus): string
    {
        return $currentActionStatus;
    }

    /** Called after SQL upgrade file is run — no extra work. */
    private function upgrade_sql($modId, string $currentActionStatus): string
    {
        return $currentActionStatus;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Registers the module's ACL section and permission objects.
     * Uses addObjectSectionAcl / addObjectAcl which are idempotent (safe to run again).
     * Identical API in OpenEMR 8.2.0 and 8.4.1.
     */
    private function registerAclSections(): void
    {
        // Section: ai_assistant
        AclExtended::addObjectSectionAcl('ai_assistant', 'AI Assistant');

        // Objects within the section
        AclExtended::addObjectAcl(
            'ai_assistant',
            'AI Assistant',
            'use',
            'Use AI Assistant (dictation and chat)'
        );
        AclExtended::addObjectAcl(
            'ai_assistant',
            'AI Assistant',
            'admin',
            'Administer AI Assistant settings'
        );
    }

    /**
     * Reads and executes a SQL file statement by statement.
     * Uses sqlStatement() (legacy helper available in all OpenEMR versions).
     */
    private function runSqlFile(string $path): void
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("SQL file not found: $path");
        }

        $sql = file_get_contents($path);
        // Split on semicolons, skip blank lines and comments
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            static fn(string $s): bool => $s !== '' && !str_starts_with($s, '--')
        );

        foreach ($statements as $statement) {
            sqlStatement($statement);
        }
    }
}
