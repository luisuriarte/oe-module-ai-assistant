<?php

/**
 * AuditLogger — writes metadata-only audit records.
 *
 * Records: user, patient, encounter, action, provider, model, status,
 * duration, token usage. NEVER logs transcripts, prompts or responses
 * unless debug_log_content is explicitly enabled in settings.
 *
 * Failure handling
 * ----------------
 * A failed audit insert must not break the clinical workflow, but it must also not be
 * silent: the original ENUM columns rejected any action outside their list with error
 * 1265 "Data truncated", and swallowing that meant audit records disappeared without a
 * trace. Now every failure:
 *
 *   1. increments a counter in the settings table so Settings can display it,
 *   2. logs only the SQL error code and the metadata we already had (action, status) —
 *      never the row payload, and never the prompt or transcript,
 *   3. triggers the idempotent schema upgrade once and retries the insert, so an
 *      existing installation self-heals instead of waiting for a manual ALTER.
 *
 * Compatibility: OpenEMR 8.2.0+ (QueryUtils methods used are in both versions).
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\AiAssistant\Audit;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;

class AuditLogger
{
    private const TABLE = 'oe_ai_assistant_audit';

    /** Setting key holding the count of failed audit inserts. */
    public const FAILURE_COUNT_KEY = 'audit_insert_failures';

    private SystemLogger $logger;

    /** Schema upgrade runs at most once per request. */
    private static bool $schemaUpgraded = false;

    public function __construct()
    {
        $this->logger = new SystemLogger();
    }

    /**
     * Logs an AI operation's metadata.
     *
     * @param int    $userId      Authenticated user ID
     * @param int    $patientId   Patient PID (0 if not patient-scoped)
     * @param int    $encounterId Encounter ID (0 if not encounter-scoped)
     * @param string $action      Metadata-only action label
     * @param string $provider    Provider name (e.g. 'openai')
     * @param string $model       Model name
     * @param string $status      'ok' | 'error' | 'blocked' | 'leak_detected'
     * @param string $errorCode   Short error code, empty on success
     * @param int    $durationMs  Request duration in milliseconds
     * @param int    $tokensIn    Prompt token count
     * @param int    $tokensOut   Completion token count
     *
     * @return bool True when the record was written
     */
    public function log(
        int    $userId,
        int    $patientId,
        int    $encounterId,
        string $action,
        string $provider,
        string $model,
        string $status,
        string $errorCode  = '',
        int    $durationMs = 0,
        int    $tokensIn   = 0,
        int    $tokensOut  = 0
    ): bool {
        $params = [
            $userId, $patientId, $encounterId, $action,
            substr($provider, 0, 64), substr($model, 0, 128),
            $status, substr($errorCode, 0, 64),
            $durationMs, $tokensIn, $tokensOut,
        ];

        if ($this->insert($params)) {
            return true;
        }

        // Self-heal an existing installation that still has the ENUM columns, then retry
        // once. upgrade.sql is idempotent, so this is safe on every call.
        if (self::$schemaUpgraded || !$this->upgradeSchema()) {
            $this->registerFailure($action, $status, $errorCode);
            return false;
        }

        if ($this->insert($params)) {
            return true;
        }

        $this->registerFailure($action, $status, $errorCode);

        return false;
    }

    /**
     * Reads the counter of failed audit inserts (0 when none are recorded).
     */
    public static function failureCount(): int
    {
        try {
            $rows = QueryUtils::fetchRecords(
                'SELECT `setting_value` FROM `oe_ai_assistant_settings` WHERE `setting_key` = ?',
                [self::FAILURE_COUNT_KEY]
            );
            if (empty($rows)) {
                return 0;
            }

            return (int) ($rows[0]['setting_value'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function insert(array $params): bool
    {
        try {
            QueryUtils::sqlStatementThrowException(
                'INSERT INTO `' . self::TABLE . '`
                 (`user_id`, `patient_id`, `encounter_id`, `action`,
                  `provider`, `model`, `status`, `error_code`,
                  `duration_ms`, `tokens_in`, `tokens_out`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                $params
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Records a failure: bumps the visible counter and logs only non-clinical detail.
     *
     * $action, $status and $errorCode are module-owned metadata — they never contain
     * prompt, transcript or patient text. The SQL exception itself is not logged,
     * because database error strings can embed the offending row values.
     */
    private function registerFailure(string $action, string $status, string $errorCode): void
    {
        try {
            QueryUtils::sqlStatementThrowException(
                'INSERT INTO `oe_ai_assistant_settings` (`setting_key`, `setting_value`)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE `setting_value` = CAST(`setting_value` AS UNSIGNED) + 1',
                [self::FAILURE_COUNT_KEY, '1']
            );
        } catch (\Throwable) {
            // The settings table may be the thing that is broken; do not recurse.
        }

        $this->logger->error(sprintf(
            '[AiAssistant] audit insert failed: action=%s status=%s error_code=%s',
            substr($action, 0, 32),
            substr($status, 0, 32),
            substr($errorCode, 0, 64)
        ));
    }

    /**
     * Applies sql/upgrade.sql. Returns false when it cannot run (so the caller stops
     * retrying the insert and records the failure instead of looping).
     */
    private function upgradeSchema(): bool
    {
        self::$schemaUpgraded = true;

        $path = __DIR__ . '/../../sql/upgrade.sql';
        if (!is_readable($path)) {
            return false;
        }

        try {
            $sql = (string) file_get_contents($path);
            // Strip comment lines BEFORE splitting: the file opens with a comment block
            // that would otherwise share the first chunk with the first ALTER and get
            // dropped together with it.
            $sql      = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
            $parts    = preg_split('/;/', $sql) ?: [];
            $executed = 0;
            foreach ($parts as $statement) {
                $statement = trim($statement);
                if ($statement === '') {
                    continue;
                }
                QueryUtils::sqlStatementThrowException($statement, []);
                $executed++;
            }

            if ($executed === 0) {
                return false;
            }

            $this->logger->info('[AiAssistant] audit schema upgraded (VARCHAR columns)');

            return true;
        } catch (\Throwable $e) {
            $this->logger->error(
                '[AiAssistant] audit schema upgrade failed: ' . $e->getCode()
            );

            return false;
        }
    }
}
