<?php

/**
 * RateLimiter — fixed-window per-user rate limiting for provider-bound endpoints.
 *
 * Covers the "rate limits" item of the M7 hardening milestone. Draft generation,
 * chat questions and audio transcription are all billed (provider or Whisper host)
 * requests, so each is capped per authenticated user over a rolling 60-second
 * window.
 *
 * Design:
 * - One row per (user_id, bucket, minute-window); the row is atomically
 *   incremented with INSERT ... ON DUPLICATE KEY UPDATE, then read back. A request
 *   that pushes the count over the configured limit is blocked and the caller
 *   receives the seconds until the window rolls over (HTTP 429 + Retry-After).
 * - `count` is a plain integer, never an ENUM, so no schema change is ever needed
 *   to add a bucket.
 * - The table is created idempotently at install (install.sql) and re-applied by
 *   upgrade.sql; expired windows are pruned opportunistically on every check so the
 *   table stays bounded without a cron job.
 *
 * Failure handling: a broken rate-limit store must never block the clinical
 * workflow, so any SQL failure FAILS OPEN (the request proceeds) and is logged.
 * The columns cannot suffer the old ENUM "Data truncated" loss the audit table
 * went through: there are no ENUMs here.
 *
 * Compatibility: OpenEMR 8.2.0+ (PHP 8.2 compatible).
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Security;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;

class RateLimiter
{
    private const TABLE          = 'oe_ai_assistant_rate_limits';
    private const WINDOW_SECONDS = 60;

    private ?SystemLogger $logger = null;

    /**
     * Records one attempt for $bucket and returns whether the user is now over quota.
     *
     * The attempt is counted unconditionally (blocked attempts still consume quota,
     * which is what stops a tight retry loop from re-entering the provider path).
     *
     * @param int    $userId         Authenticated OpenEMR user id.
     * @param string $bucket         Stable action bucket, e.g. 'soap_draft', 'chat' or 'transcribe'.
     * @param int    $limitPerMinute Max attempts allowed in the 60-second window.
     *                               0 or negative means "no limit" (request passes).
     *
     * @return int|null Seconds the caller should wait (>= 1) when blocked, null when allowed.
     */
    public function check(int $userId, string $bucket, int $limitPerMinute): ?int
    {
        if ($limitPerMinute <= 0 || $userId <= 0) {
            return null;
        }

        // Buckets are internal constants, but sanitise anyway so a mistyped caller
        // can never shape the SQL. Empty after sanitising means "no-op".
        $bucket = substr((string) preg_replace('/[^a-z0-9_]/', '', strtolower($bucket)), 0, 16);
        if ($bucket === '') {
            return null;
        }

        try {
            $now    = $this->now();
            $window = intdiv($now, self::WINDOW_SECONDS) * self::WINDOW_SECONDS;

            // Atomic increment of the current window's counter.
            QueryUtils::sqlStatementThrowException(
                'INSERT INTO `' . self::TABLE . '` (`user_id`, `bucket`, `window_start`, `count`) '
                . 'VALUES (?, ?, ?, 1) '
                . 'ON DUPLICATE KEY UPDATE `count` = `count` + 1',
                [$userId, $bucket, $window]
            );

            $rows = QueryUtils::fetchRecords(
                'SELECT `count` FROM `' . self::TABLE . '` '
                . 'WHERE `user_id` = ? AND `bucket` = ? AND `window_start` = ?',
                [$userId, $bucket, $window]
            );

            // Opportunistic cleanup: expired windows are never read again.
            QueryUtils::sqlStatementThrowException(
                'DELETE FROM `' . self::TABLE . '` WHERE `window_start` < ?',
                [$now - (2 * self::WINDOW_SECONDS)]
            );

            $count = (int) ($rows[0]['count'] ?? 1);
            if ($count > $limitPerMinute) {
                return max(1, $window + self::WINDOW_SECONDS - $now);
            }

            return null;
        } catch (\Throwable $e) {
            // Fail open: a broken rate-limit store must not break the clinical
            // workflow, but it must not be silent either.
            $this->logger()->error('[AiAssistant] rate limiter failed (fail-open): ' . $e->getMessage());
            return null;
        }
    }

    private function logger(): SystemLogger
    {
        return $this->logger ??= new SystemLogger();
    }

    /**
     * Injectable clock so tests can roll the window deterministically.
     */
    protected function now(): int
    {
        return time();
    }
}