<?php

/**
 * OpenAiAdapter — Adapter for OpenAI and OpenAI-compatible inference endpoints.
 *
 * Official Reference: https://platform.openai.com/docs/api-reference/chat
 *
 * Security & Reliability:
 *   - Configurable base URL validated against SSRF.
 *   - Bearer authorization header.
 *   - Zero logging of prompt or response content.
 *   - testConnection() uses lightweight GET /models (0 token cost).
 *
 * Compatibility: OpenEMR 8.2.0+ (PHP 8.2 compatible).
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\AiAssistant\Provider\Adapter;

use OpenEMR\Modules\AiAssistant\Provider\Exception\ProviderException;
use OpenEMR\Modules\AiAssistant\Provider\Exception\ProviderInvalidResponseException;
use OpenEMR\Modules\AiAssistant\Provider\ProviderResponse;

class OpenAiAdapter extends AbstractProviderAdapter
{
    private string $baseUrl;

    public function __construct(
        string $apiKey,
        string $model = 'gpt-4o',
        float $temperature = 0.2,
        int $maxTokens = 2048,
        string $baseUrl = 'https://api.openai.com/v1',
        int $timeoutSec = 30,
        bool $allowPrivate = false
    ) {
        parent::__construct($apiKey, $model ?: 'gpt-4o', $temperature, $maxTokens, $timeoutSec);
        $this->baseUrl = self::validateBaseUrl($baseUrl ?: 'https://api.openai.com/v1', $allowPrivate);
    }

    public function getProviderName(): string
    {
        return 'openai';
    }

    public function generate(array $messages, array $options = []): ProviderResponse
    {
        return $this->sendChatCompletion($this->buildChatPayload($messages, $options), $options);
    }

    /**
     * Builds the OpenAI-compatible Chat Completions request payload.
     *
     * Kept as a protected method so provider subclasses can add their own optional
     * parameters without duplicating the request/response plumbing, and without changing
     * the payload OpenAI itself sends.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed>                             $options
     * @return array<string, mixed>
     */
    protected function buildChatPayload(array $messages, array $options): array
    {
        return [
            'model'       => $this->model,
            'messages'    => array_values($messages),
            'temperature' => (float) ($options['temperature'] ?? $this->temperature),
            'max_tokens'  => (int) ($options['max_tokens'] ?? $this->maxTokens),
        ];
    }

    /**
     * Sends a prepared Chat Completions payload and parses the OpenAI-compatible response.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     * @throws ProviderException
     */
    protected function sendChatCompletion(array $payload, array $options): ProviderResponse
    {
        $url = $this->baseUrl . '/chat/completions';

        $headers = [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $this->apiKey,
        ];

        $requestTimeout = (int) ($options['timeout'] ?? $this->timeoutSec);
        $resp = $this->executeRequest($url, 'POST', $headers, json_encode($payload), $requestTimeout);

        $data = json_decode($resp['body'], true);
        if (!is_array($data)) {
            throw new ProviderInvalidResponseException(
                'OpenAI returned invalid or unparseable JSON response.',
                $resp['statusCode'],
                null,
                'openai'
            );
        }

        $choices = $data['choices'] ?? [];
        if (empty($choices) || !is_array($choices)) {
            throw new ProviderInvalidResponseException(
                'OpenAI response contains no choices.',
                $resp['statusCode'],
                null,
                'openai'
            );
        }

        $first        = $choices[0];
        $message      = is_array($first['message'] ?? null) ? $first['message'] : [];
        $text         = (string) ($message['content'] ?? '');
        $finishReason = (string) ($first['finish_reason'] ?? 'stop');

        $this->afterChatCompletionParsed($text, $finishReason, $message, $resp['statusCode']);

        $usage     = $data['usage'] ?? [];
        $tokensIn  = (int) ($usage['prompt_tokens'] ?? 0);
        $tokensOut = (int) ($usage['completion_tokens'] ?? 0);

        return new ProviderResponse(
            text: $text,
            tokensIn: $tokensIn,
            tokensOut: $tokensOut,
            model: $this->model,
            raw: [
                'finish_reason' => $finishReason,
                'prompt_tokens' => $tokensIn,
                'output_tokens' => $tokensOut,
                'total_tokens'  => $tokensIn + $tokensOut,
            ]
        );
    }

    /**
     * Hook invoked after a Chat Completions response is parsed, before the DTO is built.
     *
     * Default no-op: intended for provider-specific diagnostics or validation. Throwing
     * here surfaces through the normal provider exception flow.
     *
     * @param array<string, mixed> $message the choices[0].message object
     */
    protected function afterChatCompletionParsed(string $text, string $finishReason, array $message, int $statusCode): void
    {
    }

    /**
     * Tests connection using GET /models (0 token cost).
     */
    public function testConnection(): array
    {
        $start = microtime(true);
        $url   = $this->baseUrl . '/models';

        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
        ];

        try {
            $resp = $this->executeRequest($url, 'GET', $headers, null, 10);
            $latencyMs = (int) round((microtime(true) - $start) * 1000);

            $data = json_decode($resp['body'], true);
            $models = [];
            if (is_array($data) && !empty($data['data'])) {
                foreach ($data['data'] as $m) {
                    if (isset($m['id'])) {
                        $models[] = (string) $m['id'];
                    }
                }
            }

            return [
                'ok'          => true,
                'latency_ms'  => $latencyMs,
                'status_code' => $resp['statusCode'],
                'error'       => '',
                'models'      => array_slice($models, 0, 15),
            ];
        } catch (\Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $start) * 1000);
            return [
                'ok'          => false,
                'latency_ms'  => $latencyMs,
                'status_code' => (int) $e->getCode(),
                'error'       => $e->getMessage(),
            ];
        }
    }
}
