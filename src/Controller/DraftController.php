<?php

/**
 * DraftController — AJAX endpoint for generating AI SOAP note drafts.
 *
 * Security checklist:
 *   1. Active OpenEMR session.
 *   2. CSRF token validation.
 *   3. ACL check: ai_assistant / use (or admin).
 *   4. Patient access check: patients / med (or demo).
 *   5. Server-side validation that the encounter belongs to the requested patient.
 *   6. Encounter sensitivity ACL check.
 *   7. Safe timeout under Cloudflare limits.
 *   8. Metadata-only audit logging (never clinical text).
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
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Modules\AiAssistant\Session\CsrfCompat;
use OpenEMR\Modules\AiAssistant\Session\SessionAccessor;
use OpenEMR\Modules\AiAssistant\Audit\AuditLogger;
use OpenEMR\Modules\AiAssistant\Context\PatientContextBuilder;
use OpenEMR\Modules\AiAssistant\Draft\SoapDraftGenerator;
use OpenEMR\Modules\AiAssistant\Provider\Exception\ProviderAuthenticationException;
use OpenEMR\Modules\AiAssistant\Provider\Exception\ProviderException;
use OpenEMR\Modules\AiAssistant\Provider\Exception\ProviderInvalidResponseException;
use OpenEMR\Modules\AiAssistant\Provider\Exception\ProviderRateLimitException;
use OpenEMR\Modules\AiAssistant\Provider\Exception\ProviderSafetyBlockException;
use OpenEMR\Modules\AiAssistant\Provider\Exception\ProviderTimeoutException;
use OpenEMR\Modules\AiAssistant\Security\ConsentGate;
use OpenEMR\Modules\AiAssistant\Settings\SettingsManager;

class DraftController
{
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
     * AJAX action: 'soap_draft'
     * Generates a structured SOAP note draft from transcript and patient context.
     */
    public function createDraft(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        // 1. ACL Check: ai_assistant / use (admin allowed as override)
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
        $token   = $_POST['csrf_token_form'] ?? $_POST['csrf_token'] ?? '';
        if (!CsrfCompat::verify($token, $session)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => xlt('Invalid CSRF token.')]);
            return;
        }

        $userId = (int) (
            $session->get('authUserID')
            ?? $session->get('authId')
            ?? $_SESSION['authUserID']
            ?? $_SESSION['authId']
            ?? 1
        );

        // 4. Consent gate: refuse to transmit any PHI until an admin has acknowledged
        //    the third-party data disclosure AND the active provider has a saved key.
        //    Placed before patient context is built so no PHI is ever assembled.
        if (!$this->consent->isTransmissionAllowed()) {
            $reason = $this->consent->denialReason();
            $this->logger->warning('[AiAssistant] soap_draft blocked by consent gate: ' . $reason);

            $audit = new AuditLogger();
            $audit->log(
                userId: $userId,
                patientId: (int) ($_POST['pid'] ?? 0),
                encounterId: (int) ($_POST['encounter'] ?? 0),
                action: 'soap_draft',
                provider: $this->settings->getActiveProvider(),
                model: '',
                status: 'blocked',
                errorCode: $reason,
                durationMs: 0,
                tokensIn: 0,
                tokensOut: 0
            );

            http_response_code(403);
            echo json_encode([
                'ok'         => false,
                'error'      => $this->consent->denialMessage(),
                'error_type' => 'ConsentGateBlocked',
                'reason'     => $reason,
            ]);
            return;
        }

        // 5. Validate Patient ID
        $pid = (int) ($_POST['pid'] ?? 0);
        if ($pid <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => xlt('Invalid patient ID.')]);
            return;
        }

        // 6. Validate Encounter ID (if supplied)
        $encounterId = (int) ($_POST['encounter'] ?? 0);
        if ($encounterId > 0) {
            $encRow = $this->fetchEncounterRow($encounterId);
            if (empty($encRow)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => xlt('Encounter not found.')]);
                return;
            }

            // Verify encounter belongs to patient
            if ((int) ($encRow['pid'] ?? 0) !== $pid) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => xlt('Security violation: Encounter does not belong to the selected patient.')]);
                return;
            }

            // Verify encounter sensitivity
            $sensitivity = trim((string) ($encRow['sensitivity'] ?? 'normal'));
            if ($sensitivity !== '' && $sensitivity !== 'normal') {
                if (!AclMain::aclCheckCore('sensitivities', $sensitivity)) {
                    http_response_code(403);
                    echo json_encode(['ok' => false, 'error' => xlt('Access denied: You do not have permission to access this sensitive encounter.')]);
                    return;
                }
            }
        }

        // 7. Validate Transcript
        $transcript = trim((string) ($_POST['transcript'] ?? ''));
        if ($transcript === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => xlt('Transcript cannot be empty.')]);
            return;
        }

        if (mb_strlen($transcript) > 50000) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => xlt('Transcript exceeds maximum allowable length (50,000 characters).')]);
            return;
        }

        $start = microtime(true);
        $providerName = $this->settings->getActiveProvider();
        $modelName = '';

        // Prevent stdout noise from interfering with JSON output
        ob_start();

        try {
            // Build patient chart context (M4)
            $contextBuilder = new PatientContextBuilder($this->settings);
            $contextResult  = $contextBuilder->buildContext($pid, $encounterId > 0 ? $encounterId : null);
            $chartContext   = $contextResult['text'];

            // Generate structured SOAP draft (M3/M5)
            $generator = new SoapDraftGenerator($this->settings);
            $draft     = $generator->generateDraft($transcript, $chartContext);

            $durationMs   = (int) round((microtime(true) - $start) * 1000);
            $providerName = $draft['provider'];
            $modelName    = $draft['model'];

            // Clean output buffer before sending JSON
            if (ob_get_level() > 0) {
                ob_end_clean();
            }

            // Metadata-only audit log (NO clinical text is stored)
            $audit = new AuditLogger();
            $audit->log(
                userId: $userId,
                patientId: $pid,
                encounterId: $encounterId,
                action: 'soap_draft',
                provider: $providerName,
                model: $modelName,
                status: 'ok',
                errorCode: '',
                durationMs: $durationMs,
                tokensIn: $draft['tokens_in'],
                tokensOut: $draft['tokens_out']
            );

            http_response_code(200);
            echo json_encode([
                'ok'    => true,
                'draft' => [
                    'subjective' => $draft['subjective'],
                    'objective'  => $draft['objective'],
                    'assessment' => $draft['assessment'],
                    'plan'       => $draft['plan'],
                ],
                'has_verify_markers' => $draft['has_verify_markers'],
                'meta' => [
                    'tokens_in'    => $draft['tokens_in'],
                    'tokens_out'   => $draft['tokens_out'],
                    'total_tokens' => $draft['total_tokens'],
                    'duration_ms'  => $durationMs,
                    'provider'     => $draft['provider'],
                    'model'        => $draft['model'],
                ],
            ]);
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }

            $durationMs = (int) round((microtime(true) - $start) * 1000);
            $errCode    = substr((new \ReflectionClass($e))->getShortName(), 0, 64);

            // Audit record for failure (metadata-only)
            $audit = new AuditLogger();
            $audit->log(
                userId: $userId,
                patientId: $pid,
                encounterId: $encounterId,
                action: 'soap_draft',
                provider: $providerName,
                model: $modelName,
                status: 'error',
                errorCode: $errCode,
                durationMs: $durationMs,
                tokensIn: 0,
                tokensOut: 0
            );

            $friendlyMessage = $this->mapExceptionToMessage($e);

            http_response_code(400);
            echo json_encode([
                'ok'         => false,
                'error'      => $friendlyMessage,
                'error_type' => $errCode,
            ]);
        }
    }

    /**
     * Fetches encounter row by encounter ID to verify ownership and sensitivity.
     */
    private function fetchEncounterRow(int $encounterId): ?array
    {
        if (class_exists(QueryUtils::class)) {
            try {
                $rows = QueryUtils::fetchRecords(
                    'SELECT `id`, `pid`, `sensitivity` FROM `form_encounter` WHERE `encounter` = ? LIMIT 1',
                    [$encounterId]
                );
                return !empty($rows) ? $rows[0] : null;
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }

    /**
     * Maps provider exception subtypes to helpful, clinician-friendly localized messages.
     */
    private function mapExceptionToMessage(\Throwable $e): string
    {
        return match (true) {
            $e instanceof ProviderAuthenticationException =>
                xlt('AI provider authentication failed. Please verify your API key in AI Assistant Settings.'),
            $e instanceof ProviderRateLimitException =>
                xlt('AI provider rate limit reached. Please wait a few seconds before retrying.'),
            $e instanceof ProviderSafetyBlockException =>
                xlt('The consultation content was flagged by the AI provider safety filter.'),
            $e instanceof ProviderTimeoutException =>
                xlt('The AI provider request timed out. Please try again or check your network connectivity.'),
            $e instanceof ProviderInvalidResponseException =>
                xlt('The AI provider returned an invalid format. Please retry generating the note.'),
            $e instanceof ProviderException =>
                xlt('AI Provider error:') . ' ' . $e->getMessage(),
            default =>
                $e->getMessage() ?: xlt('An unexpected error occurred while generating the SOAP draft.'),
        };
    }
}
