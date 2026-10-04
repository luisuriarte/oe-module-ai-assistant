<?php

/**
 * AuditLogger — writes metadata-only audit records.
 *
 * Records: user, patient, encounter, action, provider, model, status,
 * duration, token usage. NEVER logs transcripts, prompts or responses
 * unless debug_log_content is explicitly enabled in settings.
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

    private SystemLogger $logger;

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
     * @param string $action      'transcribe' | 'draft' | 'chat'
     * @param string $provider    Provider name (e.g. 'openai')
     * @param string $model       Model name
     * @param string $status      'ok' | 'error'
     * @param string $errorCode   Short error code, empty on success
     * @param int    $durationMs  Request duration in milliseconds
     * @param int    $tokensIn    Prompt token count
     * @param int    $tokensOut   Completion token count
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
    ): void {
        try {
            QueryUtils::sqlStatementThrowException(
                'INSERT INTO `' . self::TABLE . '`
                 (`user_id`, `patient_id`, `encounter_id`, `action`,
                  `provider`, `model`, `status`, `error_code`,
                  `duration_ms`, `tokens_in`, `tokens_out`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $userId, $patientId, $encounterId, $action,
                    substr($provider, 0, 64), substr($model, 0, 128),
                    $status, substr($errorCode, 0, 64),
                    $durationMs, $tokensIn, $tokensOut,
                ]
            );
        } catch (\Throwable $e) {
            // Audit failure must not break the clinical workflow
            $this->logger->error('[AiAssistant] audit log failed: ' . $e->getMessage());
        }
    }
}
