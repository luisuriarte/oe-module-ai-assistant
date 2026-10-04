<?php

/**
 * AiProviderInterface — Standard contract for AI provider adapters.
 *
 * Implemented by OpenAI, Anthropic, and Gemini adapters.
 *
 * Security & Reliability Rules:
 *   - API keys are passed in headers, never in URLs.
 *   - Base URLs must be validated (HTTPS only, no embedded credentials, private IPs blocked).
 *   - TLS verification strictly enforced, blind redirects forbidden.
 *   - Never log prompts, responses, or provider error bodies.
 *   - On HTTP 429 throw ProviderRateLimitException without retry loops.
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\AiAssistant\Provider;

use OpenEMR\Modules\AiAssistant\Provider\Exception\ProviderException;

interface AiProviderInterface
{
    /**
     * Generates a completion from a list of normalized messages.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed>                             $options (temperature, max_tokens, etc.)
     * @return ProviderResponse
     * @throws ProviderException
     */
    public function generate(array $messages, array $options = []): ProviderResponse;

    /**
     * Performs a lightweight connection test (e.g. models.list or minimal query).
     *
     * @return array{ok: bool, latency_ms: int, status_code: int, error: string, models?: array<string>}
     */
    public function testConnection(): array;

    /**
     * Returns the provider identifier (e.g. 'gemini', 'openai', 'anthropic').
     */
    public function getProviderName(): string;
}
