<?php

/**
 * ProviderFactory — Instantiates AI provider adapters based on configuration.
 *
 * Reads decrypted API keys and model parameters from SettingsManager.
 *
 * Compatibility: OpenEMR 8.2.0+ (PHP 8.2 compatible).
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\AiAssistant\Provider;

use InvalidArgumentException;
use OpenEMR\Modules\AiAssistant\Provider\Adapter\AnthropicAdapter;
use OpenEMR\Modules\AiAssistant\Provider\Adapter\GeminiAdapter;
use OpenEMR\Modules\AiAssistant\Provider\Adapter\GrokAdapter;
use OpenEMR\Modules\AiAssistant\Provider\Adapter\OpenAiAdapter;
use OpenEMR\Modules\AiAssistant\Provider\Exception\ProviderAuthenticationException;
use OpenEMR\Modules\AiAssistant\Settings\SettingsManager;

class ProviderFactory
{
    private SettingsManager $settings;

    public function __construct(?SettingsManager $settings = null)
    {
        $this->settings = $settings ?? new SettingsManager();
    }

    /**
     * Creates an adapter instance for the given provider (or active provider if null).
     *
     * @param string|null $provider 'openai', 'anthropic', 'gemini', 'grok'
     * @param string|null $overrideKey Optional override key (e.g. from test form)
     * @return AiProviderInterface
     * @throws ProviderAuthenticationException
     * @throws InvalidArgumentException
     */
    public function create(?string $provider = null, ?string $overrideKey = null): AiProviderInterface
    {
        $targetProvider = strtolower(trim($provider ?: $this->settings->getActiveProvider()));

        $keyMap = [
            'openai'    => 'openai_api_key',
            'anthropic' => 'anthropic_api_key',
            'gemini'    => 'gemini_api_key',
            'grok'      => 'grok_api_key',
        ];

        if (!isset($keyMap[$targetProvider])) {
            throw new InvalidArgumentException("Unknown AI provider '{$targetProvider}'.");
        }

        $apiKey = $overrideKey !== null && trim($overrideKey) !== ''
            ? trim($overrideKey)
            : (string) $this->settings->get($keyMap[$targetProvider], '');

        if ($apiKey === '') {
            throw new ProviderAuthenticationException(
                "No API key configured for {$targetProvider}. Enter and save your key in settings.",
                401,
                null,
                $targetProvider
            );
        }

        $allowPrivate = ((int) $this->settings->get('provider_allow_private_hosts', 0)) === 1;

        return match ($targetProvider) {
            'gemini' => new GeminiAdapter(
                apiKey: $apiKey,
                model: (string) $this->settings->get('gemini_model', GeminiAdapter::DEFAULT_MODEL),
                temperature: (float) $this->settings->get('gemini_temperature', 0.2),
                maxTokens: (int) $this->settings->get('gemini_max_tokens', 2048),
                baseUrl: (string) $this->settings->get('gemini_base_url', 'https://generativelanguage.googleapis.com'),
            ),
            'openai' => new OpenAiAdapter(
                apiKey: $apiKey,
                model: (string) $this->settings->get('openai_model', 'gpt-4o'),
                temperature: (float) $this->settings->get('openai_temperature', 0.2),
                maxTokens: (int) $this->settings->get('openai_max_tokens', 2048),
                baseUrl: (string) $this->settings->get('openai_base_url', 'https://api.openai.com/v1'),
                allowPrivate: $allowPrivate,
            ),
            'anthropic' => new AnthropicAdapter(
                apiKey: $apiKey,
                model: (string) $this->settings->get('anthropic_model', 'claude-3-5-sonnet-20241022'),
                temperature: (float) $this->settings->get('anthropic_temperature', 0.2),
                maxTokens: (int) $this->settings->get('anthropic_max_tokens', 2048),
                baseUrl: (string) $this->settings->get('anthropic_base_url', 'https://api.anthropic.com'),
            ),
            'grok' => new GrokAdapter(
                apiKey: $apiKey,
                model: (string) $this->settings->get('grok_model', GrokAdapter::DEFAULT_MODEL),
                temperature: (float) $this->settings->get('grok_temperature', 0.2),
                maxTokens: (int) $this->settings->get('grok_max_tokens', 2048),
                baseUrl: (string) $this->settings->get('grok_base_url', GrokAdapter::DEFAULT_BASE_URL),
                allowPrivate: $allowPrivate,
            ),
        };
    }
}
