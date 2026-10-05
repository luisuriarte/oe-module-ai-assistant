<?php

/**
 * PatientContextBuilderTest — Automated simulation test suite for M4 PatientContextBuilder.
 *
 * Verifies:
 *   1. SQL Whitelist enforcement: No fname, lname, street, phone, email, SSN queried.
 *   2. DOB anonymization: Only server-computed age is returned; DOB is never exposed.
 *   3. Active medications only: Discontinued/inactive medications are strictly excluded.
 *   4. Verified active allergies: Sourced from `lists` where type='allergy' AND activity=1.
 *   5. Soft-delete exclusion: Deleted/voided SOAP notes (forms.deleted=1) are strictly excluded.
 *   6. Cross-patient isolation: Data from another patient (e.g. pid=999) is never included for pid=42.
 *   7. Encounter sensitivity ACL: Encounters with sensitivity restricted from the current user are excluded.
 *   8. Best-effort redaction: Free text emails, phone numbers, and national ID sequences are redacted.
 *   9. Token budget & truncation priority: Oldest encounters are truncated first when budget is exceeded.
 *  10. Absolute vs relative dates: Formats dates relative to current date when enabled.
 *  11. Labs gating: Included only when context_include_labs = 1.
 *  12. Automated leak check: Validates PASS when clean and FAIL when patient PII is injected.
 *
 * Standalone runnable via: `php tests/PatientContextBuilderTest.php`
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Tests;

// Standalone class autoloader
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

// Mock OpenEMR AclMain if not present in environment
if (!class_exists('OpenEMR\\Common\\Acl\\AclMain')) {
    class MockAclMain
    {
        public static array $permissions = [
            'sensitivities' => ['normal' => true, 'high' => false],
            'patients'      => ['med' => true, 'demo' => true],
            'ai_assistant'  => ['admin' => true, 'use' => true],
        ];

        public static function aclCheckCore(string $section, string $value, ?string $user = null): bool
        {
            return self::$permissions[$section][$value] ?? true;
        }
    }
    class_alias(MockAclMain::class, 'OpenEMR\\Common\\Acl\\AclMain');
}

// Mock OpenEMR xlt/xl functions if not present
if (!function_exists('xlt')) {
    function xlt(string $text): string
    {
        return $text;
    }
}
if (!function_exists('xl')) {
    function xl(string $text): string
    {
        return $text;
    }
}

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Modules\AiAssistant\Context\PatientContextBuilder;
use OpenEMR\Modules\AiAssistant\Settings\SettingsManager;

class MockSettingsManager extends SettingsManager
{
    private array $custom = [];

    public function __construct(array $custom = [])
    {
        $this->custom = $custom;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->custom[$key] ?? $default;
    }

    public function setOverride(string $key, mixed $val): void
    {
        $this->custom[$key] = $val;
    }
}

class PatientContextBuilderTest
{
    private int $passes = 0;
    private int $failures = 0;

    public function run(): void
    {
        echo "=== Running M4 PatientContextBuilder Simulation Test Suite ===\n\n";

        $this->testSqlWhitelistAndAgeOnly();
        $this->testActiveAllergiesAndProblems();
        $this->testActiveMedicationsExcludesDiscontinued();
        $this->testSoftDeletedNotesExclusion();
        $this->testCrossPatientIsolation();
        $this->testEncounterSensitivityAcl();
        $this->testFreeTextRedaction();
        $this->testTokenBudgetAndTruncationPriority();
        $this->testRelativeDatesFormatting();
        $this->testLabsSettingGating();
        $this->testAutomatedLeakCheckPass();
        $this->testAutomatedLeakCheckFail();

        echo "\n---------------------------------------------------------------\n";
        echo "Result: {$this->passes} passed, {$this->failures} failed.\n";
        if ($this->failures > 0) {
            echo "FAILED tests encountered.\n";
            exit(1);
        } else {
            echo "ALL M4 SIMULATION TESTS PASSED SUCCESSFULLY.\n";
            exit(0);
        }
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

    /**
     * Builds a simulated database router for patient 42 (and dummy patient 999).
     */
    private function createSimulatedDatabase(array $customData = []): callable
    {
        return function (string $sql, array $params = []) use ($customData): array {
            $cleanSql = str_replace(['`', '"'], '', strtolower($sql));

            // 1. Demographics query
            if (str_contains($cleanSql, 'from patient_data')) {
                // If this is queryPatientIdentifiers for leakCheck, allow reading identifiers:
                if (str_contains($cleanSql, 'phone_cell')) {
                    $pid = (int) ($params[0] ?? 0);
                    if ($pid === 42) {
                        return [
                            [
                                'fname'         => 'Catalina',
                                'lname'         => 'Gomez',
                                'mname'         => '',
                                'DOB'           => '1980-05-15',
                                'phone_cell'    => '1145678901',
                                'phone_home'    => '47891234',
                                'phone_biz'     => '',
                                'phone_contact' => '',
                                'email'         => 'catalina.gomez@gmail.com',
                                'ss'            => '30123456',
                            ]
                        ];
                    }
                    return [];
                }

                // Ensure strict whitelist for context building: never query fname, lname, phone, etc.
                if (str_contains($cleanSql, 'fname') || str_contains($cleanSql, 'lname') || str_contains($cleanSql, 'phone') || str_contains($cleanSql, 'email') || str_contains($cleanSql, 'ss')) {
                    throw new \RuntimeException('SECURITY VIOLATION: Forbidden demographic fields queried in SQL!');
                }

                $pid = (int) ($params[0] ?? 0);
                if ($pid === 42) {
                    return [
                        ['sex' => 'Female', 'DOB' => '1980-05-15']
                    ];
                }
                return [];
            }

            // 2. Active allergies query
            if (str_contains($cleanSql, "type = 'allergy'")) {
                $pid = (int) ($params[0] ?? 0);
                if ($pid === 42) {
                    return [
                        ['title' => 'Penicillin', 'comments' => 'Anaphylaxis', 'severity' => 'Severa', 'begdate' => '2015-02-10']
                    ];
                }
                return [];
            }

            // 3. Active medical problems query
            if (str_contains($cleanSql, "type = 'medical_problem'")) {
                $pid = (int) ($params[0] ?? 0);
                if ($pid === 42) {
                    return [
                        ['title' => 'Type 2 Diabetes', 'diagnosis' => 'E11.9', 'begdate' => '2018-09-01', 'comments' => 'E11.9']
                    ];
                }
                return [];
            }

            // 4. Prescriptions query (active medications only)
            if (str_contains($cleanSql, 'from prescriptions')) {
                $pid = (int) ($params[0] ?? 0);
                if ($pid === 42) {
                    return $customData['prescriptions'] ?? [
                        ['drug' => 'Metformin', 'dosage' => '500mg', 'form' => 'tablet', 'route' => 'oral', 'interval' => 'BID', 'date_added' => '2023-01-10', 'active' => 1]
                    ];
                }
                return [];
            }

            // Lists medication
            if (str_contains($cleanSql, "type = 'medication'")) {
                return [];
            }

            // 5. History data query
            if (str_contains($cleanSql, 'from history_data')) {
                $pid = (int) ($params[0] ?? 0);
                if ($pid === 42) {
                    return [
                        [
                            'tobacco'          => 'Former smoker (quit 2012)',
                            'alcohol'          => 'Occasional social wine',
                            'exercise'         => '',
                            'diet'             => '',
                            'medical_history'  => 'Appendectomy 2004, contact at dr.smith@clinic.com or phone 555-0199',
                            'surgical_history' => '',
                            'family_history'   => 'Mother: Hypertension, Father: Infarction',
                            'social_history'   => '',
                        ]
                    ];
                }
                return [];
            }

            // 6. Vitals query
            if (str_contains($cleanSql, 'from form_vitals')) {
                $pid = (int) ($params[0] ?? 0);
                if ($pid === 42) {
                    return [
                        [
                            'bps'               => '120',
                            'bpd'               => '80',
                            'pulse'             => '72',
                            'temperature'       => '36.6',
                            'respiration'       => '',
                            'oxygen_saturation' => '',
                            'BMI'               => '25',
                            'date'              => '2026-09-15 10:00:00',
                        ]
                    ];
                }
                return [];
            }

            // 7. Encounters list query
            if (str_contains($cleanSql, 'from form_encounter')) {
                $pid = (int) ($params[0] ?? 0);
                if ($pid === 42) {
                    return $customData['encounters'] ?? [
                        ['id' => 1, 'encounter' => 101, 'date' => '2026-09-15 10:00:00', 'sensitivity' => 'normal', 'reason' => 'Quarterly routine diabetes review'],
                        ['id' => 2, 'encounter' => 102, 'date' => '2026-06-10 14:30:00', 'sensitivity' => 'normal', 'reason' => 'Foot pain follow-up'],
                    ];
                }
                return [];
            }

            // 8. SOAP forms query (checks deleted = 0)
            if (str_contains($cleanSql, 'from form_soap')) {
                $pid = (int) ($params[0] ?? 0);
                $eid = (int) ($params[1] ?? 0);

                if (isset($customData['soap_records'][$eid])) {
                    return $customData['soap_records'][$eid];
                }

                if ($pid === 42 && $eid === 101) {
                    return [
                        [
                            'id'          => 501,
                            'subjective'  => 'Patient feels well, takes medication regularly. Reach sister at 11-4567-8901 or maria@test.org with ID 30.123.456.',
                            'objective'   => 'BP normal, BMI 25.',
                            'assessment'  => 'Good glycemic control.',
                            'plan'        => 'Maintain diet and Metformin 500mg.',
                            'date'        => '2026-09-15 10:00:00',
                        ]
                    ];
                }
                if ($pid === 42 && $eid === 102) {
                    return [
                        [
                            'id'          => 502,
                            'subjective'  => 'Mild heel pain after running.',
                            'objective'   => 'Tender plantar fascia.',
                            'assessment'  => 'Plantar fasciitis.',
                            'plan'        => 'Stretching exercises and supportive footwear.',
                            'date'        => '2026-06-10 14:30:00',
                        ]
                    ];
                }
                return [];
            }

            // 9. Labs query — column set mirrors the real procedure_order /
            // procedure_report / procedure_result schema, joined through procedure_report.
            if (str_contains($cleanSql, 'from procedure_order')) {
                $pid = (int) ($params[0] ?? 0);
                if ($pid === 42) {
                    return [
                        [
                            'procedure_order_type' => 'lab',
                            'order_diagnosis'      => '',
                            'result_text'          => 'HbA1c',
                            'result'               => '6.4',
                            'units'                => '%',
                            'range'                => '4.0 - 5.6',
                            'abnormal'             => 'abnormal',
                            'result_status'        => 'final',
                            'date'                 => '2026-09-10 12:00:00',
                        ]
                    ];
                }
                return [];
            }

            return [];
        };
    }

    public function testSqlWhitelistAndAgeOnly(): void
    {
        $settings = new MockSettingsManager();
        $db = $this->createSimulatedDatabase();
        $builder = new PatientContextBuilder($settings, $db);

        $context = $builder->buildContext(42)['text'];

        $this->assert(
            str_contains(strtolower($context), 'edad') && str_contains(strtolower($context), 'años'),
            'Age is computed server-side and present in demographics',
            'Expected computed age in header'
        );
        $this->assert(
            !str_contains($context, '1980-05-15'),
            'DOB is strictly omitted from output text',
            'Birthdate 1980-05-15 must not appear'
        );
        $this->assert(
            !str_contains($context, 'fname') && !str_contains($context, 'lname'),
            'SQL whitelist prevents any query of patient name',
            'Demographic name fields omitted'
        );
    }

    public function testActiveAllergiesAndProblems(): void
    {
        $settings = new MockSettingsManager();
        $db = $this->createSimulatedDatabase();
        $builder = new PatientContextBuilder($settings, $db);

        $context = $builder->buildContext(42)['text'];

        $this->assert(
            str_contains($context, 'Penicillin') && str_contains($context, 'Anaphylaxis'),
            'Active allergies from lists (type=allergy) correctly loaded',
            'Penicillin allergy present'
        );
        $this->assert(
            str_contains($context, 'Type 2 Diabetes') && str_contains($context, 'E11.9'),
            'Active problems from lists (type=medical_problem) correctly loaded',
            'Diabetes problem present'
        );
    }

    public function testActiveMedicationsExcludesDiscontinued(): void
    {
        // Custom DB query handler that verifies active=1 filter is in SQL
        $settings = new MockSettingsManager();
        $sqlTested = false;

        $db = function (string $sql, array $params = []) use (&$sqlTested): array {
            $cleanSql = str_replace(['`', '"'], '', strtolower($sql));
            if (str_contains($cleanSql, 'from prescriptions')) {
                $sqlTested = true;
                if (!str_contains($cleanSql, 'active = 1')) {
                    throw new \RuntimeException('SQL ERROR: Prescriptions query missing active = 1 filter!');
                }
                return [
                    ['drug' => 'Metformin', 'dosage' => '500mg', 'form' => 'tablet', 'route' => 'oral', 'interval' => 'BID', 'date_added' => '2023-01-10', 'active' => 1]
                ];
            }
            if (str_contains($cleanSql, 'from patient_data')) {
                return [['sex' => 'Female', 'DOB' => '1980-05-15']];
            }
            return [];
        };

        $builder = new PatientContextBuilder($settings, $db);
        $context = $builder->buildContext(42)['text'];

        $this->assert($sqlTested, 'Prescriptions SQL query was executed');
        $this->assert(str_contains($context, 'Metformin 500mg'), 'Active prescription included');
    }

    public function testSoftDeletedNotesExclusion(): void
    {
        $settings = new MockSettingsManager();
        $sqlChecked = false;

        $db = function (string $sql, array $params = []) use (&$sqlChecked): array {
            $cleanSql = str_replace(['`', '"'], '', strtolower($sql));
            if (str_contains($cleanSql, 'from form_soap')) {
                $sqlChecked = true;
                if (!str_contains($cleanSql, 'deleted = 0')) {
                    throw new \RuntimeException('SQL ERROR: form_soap query does not filter out deleted forms (f.deleted = 0)!');
                }
                // Return empty since the note is deleted
                return [];
            }
            if (str_contains($cleanSql, 'from form_encounter')) {
                return [['id' => 1, 'encounter' => 101, 'date' => '2026-09-15 10:00:00', 'sensitivity' => 'normal', 'reason' => 'Test']];
            }
            if (str_contains($cleanSql, 'from patient_data')) {
                return [['sex' => 'Female', 'DOB' => '1980-05-15']];
            }
            return [];
        };

        $builder = new PatientContextBuilder($settings, $db);
        $context = $builder->buildContext(42)['text'];

        $this->assert($sqlChecked, 'SOAP notes query explicitly enforces f.deleted = 0 soft-delete filter');
        $this->assert(!str_contains($context, 'Subjetivo:'), 'Deleted note content omitted from context');
    }

    public function testCrossPatientIsolation(): void
    {
        $settings = new MockSettingsManager();
        $db = function (string $sql, array $params = []): array {
            $cleanSql = str_replace(['`', '"'], '', strtolower($sql));
            $pid = (int) ($params[0] ?? 0);

            // If query asks for patient 42, return only patient 42
            if ($pid === 42) {
                if (str_contains($cleanSql, 'from patient_data')) {
                    return [['sex' => 'Female', 'DOB' => '1980-05-15']];
                }
                if (str_contains($cleanSql, "type = 'medical_problem'")) {
                    return [['title' => 'Asthma', 'diagnosis' => 'J45', 'begdate' => '2020-01-01', 'comments' => '']];
                }
            }

            // Patient 999 has Cancer
            if ($pid === 999) {
                if (str_contains($cleanSql, "type = 'medical_problem'")) {
                    return [['title' => 'Malignant Neoplasm', 'diagnosis' => 'C80.1', 'begdate' => '2021-01-01', 'comments' => '']];
                }
            }
            return [];
        };

        $builder = new PatientContextBuilder($settings, $db);
        $context = $builder->buildContext(42)['text'];

        $this->assert(str_contains($context, 'Asthma'), 'Includes requested patient (pid=42) data');
        $this->assert(!str_contains($context, 'Neoplasm') && !str_contains($context, 'C80.1'), 'Cross-patient boundary: pid=999 data strictly excluded');
    }

    public function testEncounterSensitivityAcl(): void
    {
        $settings = new MockSettingsManager();

        // Encounter 201 is 'high' sensitivity (user does NOT have permission)
        // Encounter 202 is 'normal' sensitivity (user DOES have permission)
        $customData = [
            'encounters' => [
                ['id' => 10, 'encounter' => 201, 'date' => '2026-09-01 10:00:00', 'sensitivity' => 'high', 'reason' => 'Sensitive psychiatric evaluation'],
                ['id' => 11, 'encounter' => 202, 'date' => '2026-08-15 11:00:00', 'sensitivity' => 'normal', 'reason' => 'Routine follow-up'],
            ],
            'soap_records' => [
                201 => [['id' => 701, 'subjective' => 'Patient reports severe panic attacks.', 'objective' => '', 'assessment' => 'Panic disorder', 'plan' => 'SSRI', 'date' => '2026-09-01 10:00:00']],
                202 => [['id' => 702, 'subjective' => 'Regular checkup.', 'objective' => '', 'assessment' => 'Healthy', 'plan' => 'Continue diet', 'date' => '2026-08-15 11:00:00']],
            ]
        ];

        $db = $this->createSimulatedDatabase($customData);
        $builder = new PatientContextBuilder($settings, $db);

        $context = $builder->buildContext(42)['text'];

        $this->assert(!str_contains($context, 'severe panic attacks'), 'High-sensitivity encounter SOAP content excluded by ACL');
        $this->assert(str_contains($context, 'Regular checkup.'), 'Normal-sensitivity SOAP note included');
    }

    public function testFreeTextRedaction(): void
    {
        $settings = new MockSettingsManager();
        $db = $this->createSimulatedDatabase();
        $builder = new PatientContextBuilder($settings, $db);

        $context = $builder->buildContext(42)['text'];

        // Raw note contained: "11-4567-8901", "maria@test.org", "30.123.456", "dr.smith@clinic.com", "555-0199"
        $this->assert(!str_contains($context, 'maria@test.org'), 'Email in SOAP note is redacted');
        $this->assert(!str_contains($context, 'dr.smith@clinic.com'), 'Email in History is redacted');
        $this->assert(str_contains($context, '[REDACTED-EMAIL]'), '[REDACTED-EMAIL] placeholder inserted');

        $this->assert(!str_contains($context, '11-4567-8901'), 'Phone number in SOAP note is redacted');
        $this->assert(str_contains($context, '[REDACTED-PHONE]'), '[REDACTED-PHONE] placeholder inserted');

        $this->assert(!str_contains($context, '30.123.456'), 'National ID sequence in free text is redacted');
        $this->assert(str_contains($context, '[REDACTED-ID]'), '[REDACTED-ID] placeholder inserted');
    }

    public function testTokenBudgetAndTruncationPriority(): void
    {
        // Set token budget to 250 tokens so encounter 301 is retained while oldest (303) is truncated
        $settings = new MockSettingsManager(['context_token_budget' => '250']);

        // 3 encounters: #301 (newest), #302 (middle), #303 (oldest)
        $customData = [
            'encounters' => [
                ['id' => 31, 'encounter' => 301, 'date' => '2026-09-20 10:00:00', 'sensitivity' => 'normal', 'reason' => 'Newest encounter'],
                ['id' => 32, 'encounter' => 302, 'date' => '2026-07-15 10:00:00', 'sensitivity' => 'normal', 'reason' => 'Intermediate encounter'],
                ['id' => 33, 'encounter' => 303, 'date' => '2026-05-10 10:00:00', 'sensitivity' => 'normal', 'reason' => 'Oldest encounter'],
            ],
            'soap_records' => [
                301 => [['id' => 801, 'subjective' => 'Newest note content.', 'objective' => 'Normal exam.', 'assessment' => 'Stable.', 'plan' => 'Continue treatment.', 'date' => '2026-09-20 10:00:00']],
                302 => [['id' => 802, 'subjective' => 'Intermediate note content.', 'objective' => 'Normal exam.', 'assessment' => 'Stable.', 'plan' => 'Continue.', 'date' => '2026-07-15 10:00:00']],
                303 => [['id' => 803, 'subjective' => 'Oldest note content that must drop first.', 'objective' => 'Normal.', 'assessment' => 'Old issue.', 'plan' => 'Old plan.', 'date' => '2026-05-10 10:00:00']],
            ]
        ];

        $db = $this->createSimulatedDatabase($customData);
        $builder = new PatientContextBuilder($settings, $db);

        $res = $builder->buildContext(42);
        $context = $res['text'];

        $this->assert(str_contains($context, 'Newest note content.'), 'Newest encounter is retained under budget constraints');
        $this->assert(!str_contains($context, 'Oldest note content that must drop first.'), 'Oldest encounter is truncated first (FIFO priority)');
        $this->assert(str_contains($context, 'Penicillin'), 'Core clinical sections (allergies) are preserved');
    }

    public function testRelativeDatesFormatting(): void
    {
        $settings = new MockSettingsManager(['context_relative_dates' => '1']);

        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $customData = [
            'encounters' => [
                ['id' => 41, 'encounter' => 401, 'date' => $today . ' 09:00:00', 'sensitivity' => 'normal', 'reason' => 'Today visit'],
                ['id' => 42, 'encounter' => 402, 'date' => $yesterday . ' 10:00:00', 'sensitivity' => 'normal', 'reason' => 'Yesterday visit'],
            ],
            'soap_records' => [
                401 => [['id' => 901, 'subjective' => 'Visit today', 'objective' => '', 'assessment' => '', 'plan' => '', 'date' => $today . ' 09:00:00']],
                402 => [['id' => 902, 'subjective' => 'Visit yesterday', 'objective' => '', 'assessment' => '', 'plan' => '', 'date' => $yesterday . ' 10:00:00']],
            ]
        ];

        $db = $this->createSimulatedDatabase($customData);
        $builder = new PatientContextBuilder($settings, $db);

        $context = $builder->buildContext(42)['text'];

        $this->assert(str_contains($context, 'hoy'), 'Relative date formatting replaces today with "hoy"');
        $this->assert(str_contains($context, 'ayer'), 'Relative date formatting replaces yesterday with "ayer"');
    }

    public function testLabsSettingGating(): void
    {
        $db = $this->createSimulatedDatabase();

        // 1. Off by default
        $settingsOff = new MockSettingsManager(['context_include_labs' => '0']);
        $builderOff = new PatientContextBuilder($settingsOff, $db);
        $contextOff = $builderOff->buildContext(42)['text'];
        $this->assert(!str_contains($contextOff, 'LABORATORIOS RECIENTES'), 'Labs are omitted when context_include_labs = 0 (default)');
        $this->assert(!str_contains($contextOff, 'HbA1c'), 'Lab test HbA1c not present when setting is off');

        // 2. Enabled by admin
        $settingsOn = new MockSettingsManager(['context_include_labs' => '1']);
        $builderOn = new PatientContextBuilder($settingsOn, $db);
        $contextOn = $builderOn->buildContext(42)['text'];
        $this->assert(str_contains($contextOn, 'LABORATORIO'), 'Labs header included when context_include_labs = 1');
        $this->assert(str_contains($contextOn, 'HbA1c') && str_contains($contextOn, '6.4'), 'Lab test HbA1c present when setting is on');
    }

    public function testAutomatedLeakCheckPass(): void
    {
        $settings = new MockSettingsManager();
        $db = $this->createSimulatedDatabase();

        $builder = new PatientContextBuilder($settings, $db);
        $context = $builder->buildContext(42)['text'];
        $result = $builder->leakCheck($context, 42);

        $this->assert($result['pass'] === true, 'Automated leak check returns PASS on clean redacted context');
        $this->assert(empty($result['leaks']), 'Leak check detected zero leaked fields');
    }

    public function testAutomatedLeakCheckFail(): void
    {
        $settings = new MockSettingsManager();
        $db = $this->createSimulatedDatabase();

        $builder = new PatientContextBuilder($settings, $db);

        // Inject intentional leak into context
        $dirtyContext = "PACIENTE: Catalina Gomez, nacida el 1980-05-15. Tel: 1145678901. Email: catalina.gomez@gmail.com.";
        $result = $builder->leakCheck($dirtyContext, 42);

        $this->assert($result['pass'] === false, 'Automated leak check returns FAIL when patient PII is detected in text');
        $this->assert(in_array('first_name', $result['leaks'], true), 'Detected first_name leak');
        $this->assert(in_array('last_name', $result['leaks'], true), 'Detected last_name leak');
        $this->assert(in_array('date_of_birth', $result['leaks'], true), 'Detected dob leak');
        $this->assert(in_array('email', $result['leaks'], true), 'Detected email leak');
        $this->assert(in_array('cell_phone', $result['leaks'], true), 'Detected phone leak');
    }
}

// Run test suite
$suite = new PatientContextBuilderTest();
$suite->run();
