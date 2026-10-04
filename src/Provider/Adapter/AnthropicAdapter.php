<?php

/**
 * AnthropicAdapter — Adapter for Anthropic Claude Messages API.
 *
 * Official Reference: https://docs.anthropic.com/en/api/messages
 *
 * Security & Reliability:
 *   - API key sent via 'x-api-key' header.
 *   - Required 'anthropic-version: 2023-06-01' header.
 *   - System prompt extracted to top-level 'system' parameter per Anthropic API spec.
 *   - Zero logging of prompt or response content.
 *   - testConnection() uses GET /v1/models or minimal message.
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

class AnthropicAdapter extends AbstractProviderAdapter
{
    private const ANTHROPIC_VERSION = '2023-06-01';
    private string $baseUrl;

    public function __construct(
        string $apiKey,
        string $model = 'claude-3-5-sonnet-20241022',
        float $temperature = 0.2,
        int $maxTokens = 2048,
        string $baseUrl = 'https://api.anthropic.com',
        int $timeoutSec = 30
    ) {
        parent::__construct($apiKey, $model ?: 'claude-3-5-sonnet-20241022', $temperature, $maxTokens, $timeoutSec);
        $this->baseUrl = self::validateBaseUrl($baseUrl ?: 'https://api.anthropic.com');
    }

    public function getProviderName(): string
    {
        return 'anthropic';
    }

    public function generate(array $messages, array $options = []): ProviderResponse
    {
        $systemText = '';
        $contents   = [];

        foreach ($messages as $msg) {
            $role    = strtolower(trim((string) ($msg['role'] ?? 'user')));
            $content = (string) ($msg['content'] ?? '');

            if ($role === 'system') {
                $systemText .= ($systemText !== '' ? "\n\n" : '') . $content;
            } elseif ($role === 'assistant') {
                $contents[] = [
                    'role'    => 'assistant',
                    'content' => $content,
                ];
            } else {
                $contents[] = [
                    'role'    => 'user',
                    'content' => $content,
                ];
            }
        }

        if (empty($contents)) {
            $contents[] = [
                'role'    => 'user',
                'content' => 'Hello',
            ];
        }

        $payload = [
            'model'       => $this->model,
            'max_tokens'  => (int) ($options['max_tokens'] ?? $this->maxTokens),
            'temperature' => (float) ($options['temperature'] ?? $this->temperature),
            'messages'    => $contents,
        ];

        if ($systemText !== '') {
            $payload['system'] = $systemText;
        }

        $url = $this->baseUrl . '/v1/messages';

        $headers = [
            'Content-Type'      => 'application/json',
            'x-api-key'         => $this->apiKey,
            'anthropic-version' => self::ANTHROPIC_VERSION,
        ];

        $resp = $this->executeRequest($url, 'POST', $headers, json_encode($payload));

        $data = json_decode($resp['body'], true);
        if (!is_array($data)) {
            throw new ProviderInvalidResponseException(
                'Anthropic returned invalid or unparseable JSON response.',
                $resp['statusCode'],
                null,
                'anthropic'
            );
        }

        $contentBlocks = $data['content'] ?? [];
        $textParts     = [];
        foreach ($contentBlocks as $block) {
            if (($block['type'] ?? '') === 'text') {
                $textParts[] = (string) ($block['text'] ?? '');
            }
        }
        $generatedText = implode('', $textParts);

        $usage     = $data['usage'] ?? [];
        $tokensIn  = (int) ($usage['input_tokens'] ?? 0);
        $tokensOut = (int) ($usage['output_tokens'] ?? 0);

        return new ProviderResponse(
            text: $generatedText,
            tokensIn: $tokensIn,
            tokensOut: $tokensOut,
            model: $this->model,
            raw: [
                'stop_reason'   => $data['stop_reason'] ?? 'end_turn',
                'prompt_tokens' => $tokensIn,
                'output_tokens' => $tokensOut,
                'total_tokens'  => $tokensIn + $tokensOut,
            ]
        );
    }

    /**
     * Tests connection using GET /v1/models (0 token cost).
     */
    public function testConnection(): array
    {
        $start = microtime(true);
        $url   = $this->baseUrl . '/v1/models';

        $headers = [
            'x-api-key'         => $this->apiKey,
            'anthropic-version' => self::ANTHROPIC_VERSION,
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
