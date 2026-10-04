<?php

/**
 * SettingsManager — reads and writes module configuration.
 *
 * Uses the oe_ai_assistant_settings table (key/value) for all settings.
 * API keys are stored encrypted via CryptoGen; all other values are plain text.
 *
 * Compatibility: OpenEMR 8.2.0+ — uses only QueryUtils methods present in 8.2.
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\AiAssistant\Settings;

use OpenEMR\Common\Crypto\CryptoGen;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;

class SettingsManager
{
    private const TABLE = 'oe_ai_assistant_settings';

    /**
     * Keys whose values must be stored encrypted at rest.
     * These are NEVER returned to the browser.
     */
    private const ENCRYPTED_KEYS = [
        'openai_api_key',
        'anthropic_api_key',
        'gemini_api_key',
    ];

    private CryptoGen $crypto;
    private SystemLogger $logger;

    public function __construct()
    {
        $this->crypto = new CryptoGen();
        $this->logger = new SystemLogger();
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Returns a single setting value. Encrypted keys are decrypted transparently.
     * Returns $default if the key does not exist.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $row = QueryUtils::fetchRecords(
            'SELECT `setting_value` FROM `' . self::TABLE . '` WHERE `setting_key` = ?',
            [$key]
        );

        if (empty($row)) {
            return $default;
        }

        $value = $row[0]['setting_value'] ?? '';

        if (in_array($key, self::ENCRYPTED_KEYS, true) && $value !== '') {
            $decrypted = $this->crypto->decryptStandard($value);
            return $decrypted !== false ? $decrypted : $default;
        }

        return $value;
    }

    /**
     * Persists a setting. Encrypted keys are encrypted before storage.
     */
    public function set(string $key, mixed $value): void
    {
        $storeValue = (string) $value;

        if (in_array($key, self::ENCRYPTED_KEYS, true) && $storeValue !== '') {
            $storeValue = $this->crypto->encryptStandard($storeValue);
        }

        // Upsert via INSERT … ON DUPLICATE KEY UPDATE
        QueryUtils::sqlStatementThrowException(
            'INSERT INTO `' . self::TABLE . '` (`setting_key`, `setting_value`)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)',
            [$key, $storeValue]
        );
    }

    /**
     * Returns all non-sensitive settings as an associative array.
     * Encrypted key VALUES are redacted (empty string returned).
     */
    public function getAll(): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT `setting_key`, `setting_value` FROM `' . self::TABLE . '` ORDER BY `setting_key`',
            []
        );

        $result = [];
        foreach ($rows as $row) {
            $k = $row['setting_key'];
            // Never expose encrypted values to callers
            $result[$k] = in_array($k, self::ENCRYPTED_KEYS, true) ? '' : $row['setting_value'];
        }
        return $result;
    }

    /**
     * Returns true if a given encrypted key has a non-empty value stored.
     * Use this to show "Key saved" without revealing the key itself.
     */
    public function hasEncryptedValue(string $key): bool
    {
        if (!in_array($key, self::ENCRYPTED_KEYS, true)) {
            return false;
        }
        $row = QueryUtils::fetchRecords(
            'SELECT `setting_value` FROM `' . self::TABLE . '` WHERE `setting_key` = ?',
            [$key]
        );
        return !empty($row) && ($row[0]['setting_value'] ?? '') !== '';
    }

    /**
     * Returns true if the admin consent flag has been acknowledged.
     */
    public function isConsentGiven(): bool
    {
        return $this->get('consent_acknowledged', '0') === '1';
    }

    /**
     * Marks the consent flag as acknowledged.
     */
    public function acknowledgeConsent(): void
    {
        $this->set('consent_acknowledged', '1');
    }

    /**
     * Returns true if the chat feature is enabled.
     */
    public function isChatEnabled(): bool
    {
        return $this->get('chat_enabled', '0') === '1';
    }

    /**
     * Returns the configured active provider name (e.g. 'openai', 'anthropic', 'gemini').
     */
    public function getActiveProvider(): string
    {
        return $this->get('active_provider', 'openai');
    }

    /**
     * Returns true if the module has minimum required configuration:
     * - Admin consent has been acknowledged.
     * - An API key exists for the active provider.
     */
    public function isConfigured(): bool
    {
        try {
            if (!$this->isConsentGiven()) {
                return false;
            }

            $provider = $this->getActiveProvider();
            $keyMap = [
                'openai'    => 'openai_api_key',
                'anthropic' => 'anthropic_api_key',
                'gemini'    => 'gemini_api_key',
            ];

            if (isset($keyMap[$provider])) {
                return $this->hasEncryptedValue($keyMap[$provider]);
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Returns default values for all known settings keys.
     * Used to pre-populate the settings form on first run.
     */
    public function getDefaults(): array
    {
        return [
            // Whisper server
            'whisper_url'            => 'http://127.0.0.1:8178',
            'whisper_timeout'        => '60',
            'whisper_max_audio_sec'  => '180',  // 3 minutes (default per spec)

            // Provider
            'active_provider'              => 'openai',
            'provider_allow_private_hosts' => '0',

            // OpenAI-compatible
            'openai_base_url'        => 'https://api.openai.com/v1',
            'openai_model'           => 'gpt-4o',
            'openai_temperature'     => '0.2',
            'openai_max_tokens'      => '2048',
            'openai_api_key'         => '',   // encrypted

            // Anthropic
            'anthropic_base_url'     => 'https://api.anthropic.com',
            'anthropic_model'        => 'claude-opus-4-5',
            'anthropic_temperature'  => '0.2',
            'anthropic_max_tokens'   => '2048',
            'anthropic_api_key'      => '',   // encrypted

            // Gemini
            'gemini_base_url'        => 'https://generativelanguage.googleapis.com',
            'gemini_model'           => 'gemini-2.0-flash',
            'gemini_temperature'     => '0.2',
            'gemini_max_tokens'      => '2048',
            'gemini_api_key'         => '',   // encrypted

            // Context
            'context_include_labs'   => '0',
            'context_num_encounters' => '5',
            'context_token_budget'   => '4000',
            'context_relative_dates' => '0',

            // Output
            'output_language'        => 'es',   // Spanish

            // Features
            'chat_enabled'           => '0',

            // Consent
            'consent_acknowledged'   => '0',

            // Audit
            'audit_retention_days'   => '90',
            'debug_log_content'      => '0',  // Off by default; logs prompts/responses if on
        ];
    }
}
