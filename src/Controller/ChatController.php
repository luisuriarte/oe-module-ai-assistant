<?php

/**
 * ChatController - AJAX endpoint for Layer 2 patient-scoped clinical chat.
 *
 * Security checklist (mirrors DraftController and TranscribeController):
 *   1. Active OpenEMR session.
 *   2. CSRF token validation.
 *   3. ACL check: ai_assistant / use (or admin).
 *   4. Patient access check: patients / med (or demo).
 *   5. Consent gate before any PHI is assembled.
 *   6. Server-side validation that the encounter exists and belongs to the patient,
 *      plus the encounter sensitivity ACL check.
 *   7. Bounded question and history sizes before they reach the provider.
 *   8. Metadata-only audit logging (never the question, answer or chart text).
 *
 * History deliberately lives in the browser tab: nothing here writes conversation
 * content to the database. Each request carries its own history and the server re-bounds
 * it with ChatService::boundHistory(), which also rejects any injected 'system' turn.
 *
 * Compatibility: OpenEMR 8.2.0+ (PHP 8.2 compatible).
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Controller;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Modules\AiAssistant\Audit\AuditLogger;
use OpenEMR\Modules\AiAssistant\Security\ConsentGate;
use OpenEMR\Modules\AiAssistant\Security\RateLimiter;
use OpenEMR\Modules\AiAssistant\Service\ChatService;
use OpenEMR\Modules\AiAssistant\Session\CsrfCompat;
use OpenEMR\Modules\AiAssistant\Session\SessionAccessor;
use OpenEMR\Modules\AiAssistant\Settings\SettingsManager;

class ChatController
{
    use ProviderErrorResponder;

    private SettingsManager $settings;
    private SystemLogger $logger;
    private ConsentGate $consent;

    public function __construct(?SettingsManager $settings = null)
    {
        $this->settings = $settings ?? new SettingsManager();
        $this->logger   = new SystemLogger();
        $this->consent  = new ConsentGate($this->settings);
    }

    /**
     * AJAX action: 'chat'
     * Answers one clinical question using only the patient's chart context.
     */
    public function send(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        // 1. ACL: ai_assistant / use (admin allowed as override)
        if (!AclMain::aclCheckCore('ai_assistant', 'use') && !AclMain::aclCheckCore('ai_assistant', 'admin')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => xlt('Access denied: module use permission required.')]);
            return;
        }

        // 2. Patient access check
        if (!AclMain::aclCheckCore('patients', 'med') && !AclMain::aclCheckCore('patients', 'demo')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => xlt('Patient access denied.')]);
            return;
        }

        // 3. CSRF token check
        $session = SessionAccessor::resolve();
        // Fail closed: without a usable session the CSRF token cannot be verified.
        if ($session === null) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_csrf']);
            return;
        }
        $token = $_POST['csrf_token_form'] ?? $_POST['csrf_token'] ?? '';
        if (!CsrfCompat::verify((string) $token, $session)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => xlt('Invalid CSRF token.')]);
            return;
        }

        $userId = SessionAccessor::currentUserId();
        if ($userId === null) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => xlt('Not authenticated.')]);
            return;
        }

        $pid       = (int) ($_POST['pid'] ?? 0);
        $encounter = (int) ($_POST['encounter'] ?? 0);

        // 4. Consent gate: chat questions and the chart context they are answered from are
        //    PHI, so the gate is checked before anything is assembled or sent.
        if (!$this->consent->isTransmissionAllowed()) {
            $reason = $this->consent->denialReason();
            $this->logger->warning('[AiAssistant] chat blocked by consent gate: ' . $reason);

            $this->audit($userId, $pid, $encounter, 'blocked', $reason, 0, 0, 0, '');

            http_response_code(403);
            echo json_encode([
                'ok'         => false,
                'error'      => $this->consent->denialMessage(),
                'error_type' => 'ConsentGateBlocked',
                'reason'     => $reason,
            ]);
            return;
        }

        // 5. Layer 2 must be enabled by an administrator. Checked server-side so a
        //    stale or hand-crafted request cannot use an endpoint the UI hides.
        if ((string) $this->settings->get('chat_enabled', '0') !== '1') {
            $this->logger->warning('[AiAssistant] chat rejected: chat panel disabled in settings');

            http_response_code(403);
            echo json_encode([
                'ok'         => false,
                'error'      => xlt('The patient chat panel is disabled. An administrator must enable it in AI Assistant Settings.'),
                'error_type' => 'ChatDisabled',
            ]);
            return;
        }

        // 6. Clinical context. The SOAP form runs in an iframe that carries neither pid
        //    nor encounter, so the encounter is resolved from the session rather than by
        //    relaxing the check.
        if ($encounter <= 0) {
            $encounter = $this->resolveEncounterFromSession();
        }

        if ($pid <= 0 || $encounter <= 0 || !$this->validateClinicalContext($pid, $encounter)) {
            http_response_code(400);
            echo json_encode([
                'ok'         => false,
                'error'      => xlt('The patient or encounter could not be validated.'),
                'error_type' => 'invalid_clinical_context',
            ]);
            return;
        }

        if (!$this->encounterSensitivityAllowed($encounter)) {
            http_response_code(403);
            echo json_encode([
                'ok'         => false,
                'error'      => xlt('Access denied: You do not have permission to access this sensitive encounter.'),
                'error_type' => 'sensitivity_denied',
            ]);
            return;
        }

        // 7. Question and history bounds. Rejected before the session lock is released so
        //    malformed input never reaches the provider at all.
        $question = trim((string) ($_POST['message'] ?? ''));
        if ($question === '') {
            http_response_code(400);
            echo json_encode([
                'ok'         => false,
                'error'      => xlt('The question cannot be empty.'),
                'error_type' => 'empty_question',
            ]);
            return;
        }
        if (mb_strlen($question) > ChatService::MAX_QUESTION_CHARS) {
            http_response_code(400);
            echo json_encode([
                'ok'         => false,
                'error'      => xlt('The question exceeds the maximum allowed length.'),
                'error_type' => 'question_too_long',
            ]);
            return;
        }

        $history = $this->decodeHistory((string) ($_POST['history'] ?? ''));

        // 7b. Rate limit: each chat question is a provider round-trip, so it is capped
        //     per authenticated user over a rolling 60-second window (M7). Enforced
        //     after input validation and before the session lock is released.
        $rateLimit  = (int) $this->settings->get('rate_limit_chat_per_min', 10);
        $retryAfter = (new RateLimiter())->check($userId, 'chat', $rateLimit);
        if ($retryAfter !== null) {
            $this->logger->warning(
                '[AiAssistant] chat rate limited: user=' . $userId . ' retry_after=' . $retryAfter
            );

            // Metadata-only audit, identical shape to a consent-gate denial.
            $this->audit($userId, $pid, $encounter, 'blocked', 'rate_limited', 0, 0, 0, $this->settings->getActiveProvider(), '');

            header('Retry-After: ' . $retryAfter);
            http_response_code(429);
            echo json_encode([
                'ok'          => false,
                'error'       => xlt('Too many requests. Please wait a few seconds and try again.'),
                'error_type'  => 'rate_limited',
                'retry_after' => $retryAfter,
            ]);
            return;
        }

        // 8. Release the session lock before the provider round-trip, exactly as
        //    DraftController::createDraft does: the request is fully authenticated and
        //    clinically validated at this point, and holding the lock for the duration of
        //    a network call can exceed max_execution_time.
        $sessionObj = SessionAccessor::resolve();
        if ($sessionObj !== null) {
            try {
                if (method_exists($sessionObj, 'close')) {
                    $sessionObj->close();
                } elseif (method_exists($sessionObj, 'save')) {
                    $sessionObj->save($sessionObj->getId() ?? '');
                } else {
                    session_write_close();
                }
            } catch (\Throwable) {
                // A non-writable session must not abort a request that is already
                // fully validated.
            }
        }

        $start        = microtime(true);
        $providerName = $this->settings->getActiveProvider();
        $modelName    = '';

        ob_start();

        try {
            $chatService = new ChatService($this->settings);
            $result      = $chatService->ask($question, $history, $pid, $encounter);

            $durationMs   = (int) round((microtime(true) - $start) * 1000);
            $providerName = $result['provider'];
            $modelName    = $result['model'];

            if (ob_get_level() > 0) {
                ob_end_clean();
            }

            // Metadata-only: the question, the answer and the chart text are never stored.
            $this->audit($userId, $pid, $encounter, 'ok', '', $durationMs, $result['tokens_in'], $result['tokens_out'], $providerName, $modelName);

            http_response_code(200);
            echo json_encode([
                'ok'        => true,
                'reply'     => $result['reply'],
                'citations' => $result['citations'],
                'meta'      => [
                    'tokens_in'    => $result['tokens_in'],
                    'tokens_out'   => $result['tokens_out'],
                    'total_tokens' => $result['tokens_in'] + $result['tokens_out'],
                    'duration_ms'  => $durationMs,
                    'provider'     => $result['provider'],
                    'model'        => $result['model'],
                ],
            ]);
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }

            $durationMs = (int) round((microtime(true) - $start) * 1000);
            $errCode    = $this->fixedErrorCode($e);

            // Only the fixed code and the sanitized provider detail are logged: the
            // exception message can carry prompt content echoed back by the provider.
            $detail = method_exists($e, 'errorDetail') ? (string) $e->errorDetail() : '';
            $this->logger->error(
                '[AiAssistant] chat failed: ' . $errCode
                . ($detail !== '' ? ' ' . $detail : '')
                . ' duration_ms=' . $durationMs
            );

            $this->audit($userId, $pid, $encounter, 'error', $errCode, $durationMs, 0, 0, $providerName, $modelName);

            http_response_code(400);
            echo json_encode([
                'ok'         => false,
                'error'      => $this->mapExceptionToMessage(
                    $e,
                    xlt('An unexpected error occurred while answering the question.')
                ),
                'error_type' => $errCode,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Writes a metadata-only audit row. No question, answer or chart text is recorded.
     */
    private function audit(
        int $userId,
        int $pid,
        int $encounter,
        string $status,
        string $errorCode,
        int $durationMs,
        int $tokensIn,
        int $tokensOut,
        string $provider,
        string $model = ''
    ): void {
        $audit = new AuditLogger();
        $audit->log(
            userId: $userId,
            patientId: $pid,
            encounterId: $encounter,
            action: 'chat',
            provider: $provider,
            model: $model,
            status: $status,
            errorCode: $errorCode,
            durationMs: $durationMs,
            tokensIn: $tokensIn,
            tokensOut: $tokensOut
        );
    }

    /**
     * Decodes the client-supplied history. Anything that is not a JSON array of turns is
     * treated as an empty history rather than an error: the conversation must still work
     * for the current question.
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function decodeHistory(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        // ChatService applies the authoritative bounds (turn count, characters, roles).
        return (new ChatService($this->settings))->boundHistory($decoded);
    }

    /**
     * Resolves the current encounter ID from the OpenEMR session.
     *
     * The SOAP iframe does not carry an encounter parameter; EncounterSessionUtil sets it
     * when the encounter tab is opened. Returns 0 when none is active, which the caller
     * treats as a validation failure.
     */
    private function resolveEncounterFromSession(): int
    {
        try {
            $session = SessionAccessor::resolve();
            if ($session !== null) {
                $fromSession = (int) ($session->get('encounter') ?? 0);
                if ($fromSession > 0) {
                    return $fromSession;
                }
            }

            $fromGlobal = (int) ($GLOBALS['encounter'] ?? 0);
            return $fromGlobal > 0 ? $fromGlobal : 0;
        } catch (\Throwable $e) {
            $this->logger->error('[AiAssistant] encounter session lookup failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Validates that the patient exists and that the encounter belongs to that patient.
     */
    private function validateClinicalContext(int $pid, int $encounter): bool
    {
        try {
            $patient = QueryUtils::fetchRecords(
                'SELECT `pid` FROM `patient_data` WHERE `pid` = ? LIMIT 1',
                [$pid]
            );
            if (empty($patient)) {
                return false;
            }

            $enc = QueryUtils::fetchRecords(
                'SELECT `encounter` FROM `form_encounter` WHERE `pid` = ? AND `encounter` = ? LIMIT 1',
                [$pid, $encounter]
            );
            return !empty($enc);
        } catch (\Throwable $e) {
            $this->logger->error('[AiAssistant] clinical context check failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Enforces the encounter sensitivity ACL, identical to DraftController.
     */
    private function encounterSensitivityAllowed(int $encounter): bool
    {
        try {
            if (!class_exists(QueryUtils::class)) {
                return true;
            }

            $rows = QueryUtils::fetchRecords(
                'SELECT `sensitivity` FROM `form_encounter` WHERE `encounter` = ? LIMIT 1',
                [$encounter]
            );
            if (empty($rows)) {
                return true;
            }

            $sensitivity = trim((string) ($rows[0]['sensitivity'] ?? 'normal'));
            if ($sensitivity === '' || $sensitivity === 'normal') {
                return true;
            }

            return (bool) AclMain::aclCheckCore('sensitivities', $sensitivity);
        } catch (\Throwable $e) {
            $this->logger->error('[AiAssistant] sensitivity check failed: ' . $e->getMessage());
            // Fail closed: an unreadable sensitivity value must not grant access.
            return false;
        }
    }
}
