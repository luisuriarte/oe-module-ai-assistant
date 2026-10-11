<?php

/**
 * AclGuardTest — standalone tests for Security\AclGuard and for the removal of the
 * module's own 'ai_assistant' ACL.
 *
 * No network, no database, no OpenEMR bootstrap. AclGuard's two overridable seams
 * (checkForm / checkCore) are replaced by a subclass, so the real AclMain is never
 * touched. The final case scans the source tree for any leftover use of the old module
 * ACL as an ACL section.
 *
 * Run: php tests/AclGuardTest.php
 */

require_once __DIR__ . '/../src/Security/AclGuard.php';

use OpenEMR\Modules\AiAssistant\Security\AclGuard;

/**
 * Test double: records seam calls and returns/throws whatever the current case needs.
 */
class TestableAclGuard extends AclGuard
{
    /** @var array<int, array{0: string, 1: array<int, string>}> */
    public static array $forms = [];

    /** @var array<int, array{0: string, 1: string}> */
    public static array $cores = [];

    public static bool $formReturn = false;
    public static bool $coreReturn = false;
    public static ?\Throwable $throw = null;

    protected static function checkForm(string $formdir, array $returnValues): bool
    {
        self::$forms[] = [$formdir, $returnValues];
        if (self::$throw !== null) {
            throw self::$throw;
        }
        return self::$formReturn;
    }

    protected static function checkCore(string $section, string $value): bool
    {
        self::$cores[] = [$section, $value];
        if (self::$throw !== null) {
            throw self::$throw;
        }
        return self::$coreReturn;
    }

    public static function reset(): void
    {
        self::$forms      = [];
        self::$cores      = [];
        self::$formReturn = false;
        self::$coreReturn = false;
        self::$throw      = null;
    }
}

class AclGuardTest
{
    private int $passes = 0;
    private int $failures = 0;

    public function run(): void
    {
        echo "=== Running AclGuard & legacy-ACL removal test suite ===\n\n";

        $this->testCanUseReturnsFormResult();
        $this->testCanAdminReturnsCoreResult();
        $this->testFailsClosedOnThrowable();
        $this->testNoLegacyModuleAclInSourceTree();

        echo "\n---------------------------------------------------------------\n";
        echo "Result: {$this->passes} passed, {$this->failures} failed.\n";
        if ($this->failures > 0) {
            echo "FAILED tests encountered.\n";
            exit(1);
        }
        echo "ALL ACL GUARD TESTS PASSED SUCCESSFULLY.\n";
        exit(0);
    }

    private function assert(bool $condition, string $testName, string $details = ''): void
    {
        if ($condition) {
            $this->passes++;
            echo "  [PASS] {$testName}\n";
        } else {
            $this->failures++;
            echo "  [FAIL] {$testName}" . ($details ? ": {$details}" : '') . "\n";
        }
    }

    private function testCanUseReturnsFormResult(): void
    {
        foreach ([true, false] as $expected) {
            TestableAclGuard::reset();
            TestableAclGuard::$formReturn = $expected;

            $got = TestableAclGuard::canUse();

            $this->assert(
                $got === $expected,
                'canUse() returns aclCheckForm result (' . ($expected ? 'allow' : 'deny') . ')'
            );
            $this->assert(
                TestableAclGuard::$forms === [['soap', ['write', 'addonly']]],
                'canUse() checks the native soap form with user "" and return values write/addonly',
                json_encode(TestableAclGuard::$forms)
            );
        }
    }

    private function testCanAdminReturnsCoreResult(): void
    {
        foreach ([true, false] as $expected) {
            TestableAclGuard::reset();
            TestableAclGuard::$coreReturn = $expected;

            $got = TestableAclGuard::canAdmin();

            $this->assert(
                $got === $expected,
                'canAdmin() returns aclCheckCore result (' . ($expected ? 'allow' : 'deny') . ')'
            );
            $this->assert(
                TestableAclGuard::$cores === [['admin', 'super']],
                'canAdmin() checks admin/super',
                json_encode(TestableAclGuard::$cores)
            );
        }
    }

    private function testFailsClosedOnThrowable(): void
    {
        TestableAclGuard::reset();
        TestableAclGuard::$throw = new \RuntimeException('simulated ACL failure');

        $this->assert(TestableAclGuard::canUse() === false, 'canUse() denies by default when the ACL call throws');
        $this->assert(TestableAclGuard::canAdmin() === false, 'canAdmin() denies by default when the ACL call throws');
    }

    /**
     * Scans src/, public/, templates/ and openemr.bootstrap.php for any leftover use of
     * the module's own ACL: a section check that names 'ai_assistant', or the removed
     * route-table pair 'ai_assistant' => 'use' / 'admin'. Comments are ignored.
     */
    private function testNoLegacyModuleAclInSourceTree(): void
    {
        $root  = dirname(__DIR__);
        $files = [];

        foreach (['src', 'public', 'templates'] as $dir) {
            $path = $root . DIRECTORY_SEPARATOR . $dir;
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        $bootstrap = $root . DIRECTORY_SEPARATOR . 'openemr.bootstrap.php';
        if (is_file($bootstrap)) {
            $files[] = $bootstrap;
        }

        $patterns = [
            // A section check that names the removed module ACL.
            '/(?:aclCheckCore|aclCheckForm|aclCheckAcoSpec|addObjectSectionAcl|addObjectAcl)\s*\(\s*[\'"]ai_assistant[\'"]/',
            // The old route-table / registration pair.
            '/[\'"]ai_assistant[\'"]\s*,\s*[\'"](?:use|admin)[\'"]/',
        ];

        $offenders = [];
        foreach ($files as $file) {
            $code = $this->stripPhpComments((string) file_get_contents($file));
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $code) === 1) {
                    $offenders[] = str_replace($root . DIRECTORY_SEPARATOR, '', $file);
                    break;
                }
            }
        }

        $this->assert(
            $offenders === [],
            'no source file still uses the module ACL section "ai_assistant"',
            implode(', ', $offenders)
        );
    }

    /**
     * Removes comments using the PHP tokenizer so a mention inside a comment cannot trip
     * the scan.
     */
    private function stripPhpComments(string $code): string
    {
        $out = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        return $out;
    }
}

(new AclGuardTest())->run();
