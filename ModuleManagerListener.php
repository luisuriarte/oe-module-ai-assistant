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
            // Creates the table when fresh; on a re-install it is a harmless no-op
            // if columns already have the target definition.
            $this->runUpgradeFile();
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
     * Called when the module is enabled. Re-applies the idempotent schema upgrade so
     * an installation that already had the ENUM columns is migrated to VARCHAR(32)
     * the next time an admin enables the module.
     */
    private function enable($modId, string $currentActionStatus): string
    {
        try {
            $this->runUpgradeFile();
        } catch (\Throwable $e) {
            // Enabling must not fail because of a schema upgrade problem: the module
            // still runs, and AuditLogger retries the upgrade lazily on first write.
            $this->logger->error(
                '[AiAssistant] schema upgrade on enable failed: ' . $e->getMessage()
            );
        }
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
            $this->unregisterAclSections();
            $this->runSqlFile(__DIR__ . '/sql/uninstall.sql');
        } catch (\Throwable $e) {
            $this->logger->error(
                '[AiAssistant] unregister failed: ' . $e->getMessage()
            );
            return 'Error: ' . $e->getMessage();
        }
        return $currentActionStatus;
    }

    /**
     * Removes the module's ACL section and permission objects on unregister.
     */
    private function unregisterAclSections(): void
    {
        $gaclClass = '\OpenEMR\Gacl\GaclAdminApi';
        if (!class_exists($gaclClass)) {
            $gaclClass = '\OpenEMR\Gacl\GaclApi';
        }
        if (class_exists($gaclClass)) {
            $gacl = new $gaclClass();
            foreach (['use', 'admin'] as $obj) {
                $objId = $gacl->get_object_id('ai_assistant', $obj, 'ACO');
                if ($objId) {
                    $gacl->del_object($objId, 'ACO', true);
                }
            }
            $secId = $gacl->get_object_section_section_id(null, 'ai_assistant', 'ACO');
            if ($secId) {
                $gacl->del_object_section($secId, 'ACO', true);
            }
        }
    }

    /** Called after SQL install file is run by Module Manager — no extra work. */
    private function install_sql($modId, string $currentActionStatus): string
    {
        return $currentActionStatus;
    }

    /** Called after SQL upgrade file is run — applies our own idempotent upgrade. */
    private function upgrade_sql($modId, string $currentActionStatus): string
    {
        try {
            $this->runUpgradeFile();
        } catch (\Throwable $e) {
            $this->logger->error(
                '[AiAssistant] upgrade_sql failed: ' . $e->getMessage()
            );
            return 'Error: ' . $e->getMessage();
        }
        return $currentActionStatus;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Runs sql/upgrade.sql (idempotent). */
    private function runUpgradeFile(): void
    {
        $path = __DIR__ . '/sql/upgrade.sql';
        if (!file_exists($path)) {
            return;
        }
        foreach (self::splitSqlStatements($path) as $statement) {
            sqlStatement($statement);
        }
    }

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

        foreach (self::splitSqlStatements($path) as $statement) {
            sqlStatement($statement);
        }
    }

    /**
     * Splits a SQL file into executable statements, dropping `--` comment lines first.
     * Comment lines must be stripped before splitting: otherwise a file that opens with
     * a comment block puts that block in the same chunk as the first statement, and a
     * naive "skip chunks starting with --" filter would drop the statement too.
     *
     * @return string[]
     */
    private static function splitSqlStatements(string $path): array
    {
        $sql = (string) file_get_contents($path);
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;

        return array_values(array_filter(
            array_map('trim', explode(';', $sql)),
            static fn(string $s): bool => $s !== ''
        ));
    }
}
