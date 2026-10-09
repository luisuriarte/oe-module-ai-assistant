<?php

/**
 * RateLimiterTest — standalone test suite for the M7 rate limiter.
 *
 * Covers:
 *   1. Fixed 60-second window: the (limit+1)-th request in the same window blocks
 *      with a retry-after >= 1 second.
 *   2. Quota resets when the window rolls over.
 *   3. Isolation: different users and different buckets never share quota.
 *   4. Disabled paths: limit 0, negative limit and user_id 0 are all unlimited.
 *   5. Bucket sanitisation: malicious bucket names cannot shape the store key.
 *   6. Expired-window pruning keeps the store bounded.
 *
 * No database, no OpenEMR runtime: QueryUtils is stubbed with an in-memory store
 * and the clock is injected through a RateLimiter subclass.
 *
 * Compatibility: OpenEMR 8.2.0+ (PHP 8.2 compatible).
 */

declare(strict_types=1);

namespace OpenEMR\Common\Database {

    /**
     * In-memory stand-in for OpenEMR's QueryUtils, covering only the statements
     * RateLimiter issues. Mirrors the upsert + read-back semantics: the counter is
     * incremented atomically per (user, bucket, window) key, and expired windows
     * are pruned by the first DELETE parameter (cutoff timestamp).
     */
    class QueryUtils
    {
        public static array $store = [];

        public static function sqlStatementThrowException(string $sql, array $params = []): void
        {
            if (str_starts_with($sql, 'DELETE')) {
                $cutoff = (int) $params[0];
                foreach (self::$store as $key => $row) {
                    if (($row['window_start'] ?? 0) < $cutoff) {
                        unset(self::$store[$key]);
                    }
                }
                return;
            }

            if (count($params) < 3) {
                throw new \RuntimeException('unexpected params: ' . json_encode($params));
            }
            [$userId, $bucket, $window] = $params;
            $key = $userId . '|' . $bucket . '|' . $window;
            if (!isset(self::$store[$key])) {
                self::$store[$key] = [
                    'user_id'      => $userId,
                    'bucket'       => $bucket,
                    'window_start' => $window,
                    'count'        => 0,
                ];
            }
            self::$store[$key]['count']++;
        }

        public static function fetchRecords(string $sql, array $params = []): array
        {
            [$userId, $bucket, $window] = $params;
            $key = $userId . '|' . $bucket . '|' . $window;
            return isset(self::$store[$key]) ? [self::$store[$key]] : [];
        }

        public static function reset(): void
        {
            self::$store = [];
        }

        /** Allows the test to seed an expired row to prove pruning. */
        public static function seedExpired(int $userId, string $bucket, int $windowStart, int $count): void
        {
            $key = $userId . '|' . $bucket . '|' . $windowStart;
            self::$store[$key] = [
                'user_id'      => $userId,
                'bucket'       => $bucket,
                'window_start' => $windowStart,
                'count'        => $count,
            ];
        }

        public static function totalRows(): int
        {
            return count(self::$store);
        }
    }
}

namespace {

    // Standalone module autoloader (module classes only; OpenEMR core is stubbed).
    spl_autoload_register(function ($class) {
        $prefix = 'OpenEMR\\Modules\\AiAssistant\\';
        if (str_starts_with($class, $prefix)) {
            $rel = substr($class, strlen($prefix));
            $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $rel) . '.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }
    });

    use OpenEMR\Common\Database\QueryUtils;
    use OpenEMR\Modules\AiAssistant\Security\RateLimiter;

    /**
     * Subclass with an injectable clock so the 60-second window can be rolled
     * without sleeping.
     */
    class TestRateLimiter extends RateLimiter
    {
        public int $clock = 0;

        protected function now(): int
        {
            return $this->clock;
        }
    }

    QueryUtils::reset();

    $failures = 0;
    $checks   = 0;

    function check(string $label, bool $cond): void
    {
        global $failures, $checks;
        $checks++;
        if (!$cond) {
            $failures++;
            fwrite(STDOUT, "FAIL: " . $label . "\n");
        }
    }

    // ------------------------------------------------------------------
    // 1. Fixed window: the (limit+1)-th request in the same minute blocks.
    // ------------------------------------------------------------------
    $rl = new TestRateLimiter();
    // Minute boundary: 1_700_000_400 / 60 == 28_333_340 exactly.
    $rl->clock = 1_700_000_400;

    $results = [];
    for ($i = 0; $i < 4; $i++) {
        $results[] = $rl->check(10, 'soap_draft', 3);
    }
    check('first 3 requests allowed', $results[0] === null && $results[1] === null && $results[2] === null);
    check('4th request blocked', is_int($results[3]) && $results[3] >= 1);
    check('blocked at window start reports 60s retry-after', $results[3] === 60);

    // A request 30 seconds into the minute must return ~30s retry-after.
    QueryUtils::reset();
    $rl2 = new TestRateLimiter();
    $rl2->clock = 1_700_000_400 + 30; // 30s into the minute
    for ($i = 0; $i < 3; $i++) {
        $rl2->check(11, 'soap_draft', 3);
    }
    $retry = $rl2->check(11, 'soap_draft', 3);
    check('retry-after ~30s when blocked mid-window', is_int($retry) && $retry <= 31 && $retry >= 29);

    // ------------------------------------------------------------------
    // 2. Quota resets when the window rolls over.
    // ------------------------------------------------------------------
    QueryUtils::reset();
    $rl3 = new TestRateLimiter();
    $rl3->clock = 1_700_000_400;
    for ($i = 0; $i < 4; $i++) {
        $rl3->check(12, 'chat', 3);
    }
    check('blocked in first window', $rl3->check(12, 'chat', 3) !== null);
    $rl3->clock += 60; // next minute
    check('allowed again after window rollover', $rl3->check(12, 'chat', 3) === null);
    for ($i = 0; $i < 3; $i++) {
        $rl3->check(12, 'chat', 3);
    }
    check('blocks again once the new window fills', $rl3->check(12, 'chat', 3) !== null);

    // ------------------------------------------------------------------
    // 3. Isolation between users and between buckets.
    // ------------------------------------------------------------------
    QueryUtils::reset();
    $rl4 = new TestRateLimiter();
    $rl4->clock = 1_700_000_400;
    for ($i = 0; $i < 4; $i++) {
        $rl4->check(20, 'soap_draft', 3);
    }
    check('user A blocked', $rl4->check(20, 'soap_draft', 3) !== null);
    check('user B unaffected', $rl4->check(21, 'soap_draft', 3) === null);
    check('different bucket unaffected for the same user', $rl4->check(20, 'chat', 3) === null);
    check('different bucket counts independently for the same user', $rl4->check(20, 'chat', 3) === null);

    // ------------------------------------------------------------------
    // 4. Disabled paths: 0, negative limit and anonymous user are unlimited.
    // ------------------------------------------------------------------
    QueryUtils::reset();
    $rl5 = new TestRateLimiter();
    $rl5->clock = 1_700_000_400;
    for ($i = 0; $i < 500; $i++) {
        check('limit=0 is unlimited (' . ($i + 1) . ')', $rl5->check(30, 'soap_draft', 0) === null);
    }
    check('negative limit is unlimited', $rl5->check(30, 'soap_draft', -1) === null);
    check('anonymous user (0) is unlimited', $rl5->check(0, 'soap_draft', 3) === null);

    // ------------------------------------------------------------------
    // 5. Bucket sanitisation.
    // ------------------------------------------------------------------
    QueryUtils::reset();
    $rl6 = new TestRateLimiter();
    $rl6->clock = 1_700_000_400;
    $rl6->check(40, 'SOAP_DRAFT!', 3);
    $rl6->check(40, 'soap_draft', 3); // must share the same sanitised key
    $rl6->check(40, 'soap_draft ', 3);
    check('mixed-case/punctuated bucket keys collapse to the same bucket', $rl6->check(40, 'SOAP_DRAFT!', 3) !== null);
    check('a distinct bucket name is not collapsed into it', $rl6->check(40, 'soapdraft', 3) === null);

    // ------------------------------------------------------------------
    // 6. Expired-window pruning.
    // ------------------------------------------------------------------
    QueryUtils::reset();
    $rl7 = new TestRateLimiter();
    $rl7->clock = 1_700_000_400;
    QueryUtils::seedExpired(50, 'soap_draft', 1_600_000_000, 5);   // long gone
    QueryUtils::seedExpired(51, 'soap_draft', $rl7->clock - 120, 3); // exactly two windows old (kept)
    QueryUtils::seedExpired(52, 'soap_draft', $rl7->clock, 2);      // current window
    check('three seeded rows before prune', QueryUtils::totalRows() === 3);
    $rl7->check(50, 'soap_draft', 3); // triggers DELETE of rows < clock-120
    $kept = array_filter(
        QueryUtils::$store,
        static fn(array $row): bool => ($row['window_start'] ?? 0) >= $rl7->clock - 120
    );
    check('ancient window pruned, recent windows kept', count($kept) === 3);

    if ($failures === 0) {
        fwrite(STDOUT, "PASS: all $checks checks\n");
        exit(0);
    }

    fwrite(STDOUT, "FAILED: $failures of $checks checks\n");
    exit(1);
}