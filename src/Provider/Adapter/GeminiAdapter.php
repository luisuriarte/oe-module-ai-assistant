<?php

/**
 * GeminiAdapter — Adapter for Google Gemini API (Google AI Studio).
 *
 * Official Reference: https://ai.google.dev/api/rest/v1beta/models/generateContent
 *
 * Security & Reliability:
 *   - API key strictly sent via HTTP header 'x-goog-api-key' (NEVER in URL query parameters).
 *   - Base URL strictly validated (HTTPS only, no private IPs).
 *   - Maps finishReason === 'SAFETY', promptFeedback blocks, or empty candidates to ProviderSafetyBlockException.
 *   - testConnection() uses lightweight GET /v1beta/models (0 token cost).
 *   - Zero logging of prompt or response content.
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
use OpenEMR\Modules\AiAssistant\Provider\Exception\ProviderSafetyBlockException;
use OpenEMR\Modules\AiAssistant\Provider\ProviderResponse;

class GeminiAdapter extends AbstractProviderAdapter
{
    private string $baseUrl;

    /**
     * Current GA model. gemini-2.0-flash was retired and now returns HTTP 404.
     * Reference: https://ai.google.dev/gemini-api/docs/models/gemini-3.8-flash
     */
    public const DEFAULT_MODEL = 'gemini-3.8-flash';

    public function __construct(
        string $apiKey,
        string $model = self::DEFAULT_MODEL,
        float $temperature = 0.2,
        int $maxTokens = 2048,
        string $baseUrl = 'https://generativelanguage.googleapis.com',
        int $timeoutSec = 30
    ) {
        // Accept both "gemini-3.8-flash" and "models/gemini-3.8-flash". The REST path is
        // built as /v1beta/models/{model}, so a stored "models/" prefix would otherwise
        // produce /v1beta/models/models%2Fgemini-3.8-flash and a 404.
        $normalized = self::normalizeModel($model);

        parent::__construct(
            $apiKey,
            $normalized !== '' ? $normalized : self::DEFAULT_MODEL,
            $temperature,
            $maxTokens,
            $timeoutSec
        );
        $this->baseUrl = self::validateBaseUrl($baseUrl ?: 'https://generativelanguage.googleapis.com');
    }

    /**
     * Strips the optional "models/" prefix and surrounding whitespace.
     */
    private static function normalizeModel(string $model): string
    {
        $model = trim($model);
        if (stripos($model, 'models/') === 0) {
            $model = substr($model, 7);
        }

        return trim($model);
    }

public function getProviderName(): string
    {
        return 'gemini';
    }

    /**
     * True for pre-3.x Gemini models, which still accept temperature / top_p / top_k.
     *
     * Reference: https://ai.google.dev/gemini-api/docs/deprecations
     */
    private function supportsSamplingParams(): bool
    {
        if (preg_match('/(\d+)(?:\.(\d+))?/', $this->model, $m) !== 1) {
            // Unrecognised name: assume modern behaviour and omit sampling params.
            return false;
        }

        $major = (int) $m[1];

        return $major < 3;
    }

    /**
     * Generates content using the Gemini v1beta REST API.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed>                             $options
     * @throws ProviderException
     */
    public function generate(array $messages, array $options = []): ProviderResponse
    {
        $systemText = '';
        $contents   = [];

        foreach ($messages as $msg) {
            $role    = strtolower(trim((string) ($msg['role'] ?? 'user')));
            $content = (string) ($msg['content'] ?? '');

            if ($role === 'system') {
                $systemText .= ($systemText !== '' ? "\n\n" : '') . $content;
            } elseif ($role === 'assistant' || $role === 'model') {
                $contents[] = [
                    'role'  => 'model',
                    'parts' => [['text' => $content]],
                ];
            } else {
                $contents[] = [
                    'role'  => 'user',
                    'parts' => [['text' => $content]],
                ];
            }
        }

        // If no user content provided, add a minimal user prompt
        if (empty($contents)) {
            $contents[] = [
                'role'  => 'user',
                'parts' => [['text' => 'Hello']],
            ];
        }

        $payload = [
            'contents'         => $contents,
            'generationConfig' => [
                'maxOutputTokens' => (int) ($options['max_tokens'] ?? $this->maxTokens),
            ],
        ];

        // Gemini 3.x (gemini-3.8-flash and later) asks that sampling parameters be left at
        // their defaults: setting temperature explicitly — especially below 1.0, which this
        // module used for "clinical precision" — causes looping and degraded output. Only send
        // it for pre-3.x models that still accept it.
        if ($this->supportsSamplingParams()) {
            $payload['generationConfig']['temperature'] = (float) ($options['temperature'] ?? $this->temperature);
        }

        if ($systemText !== '') {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $systemText]],
            ];
        }

        $url = $this->baseUrl . '/v1beta/models/' . rawurlencode($this->model) . ':generateContent';

        $headers = [
            'Content-Type'   => 'application/json',
            'x-goog-api-key' => $this->apiKey,
        ];

        $resp = $this->executeRequest($url, 'POST', $headers, json_encode($payload));

        $data = json_decode($resp['body'], true);
        if (!is_array($data)) {
            throw new ProviderInvalidResponseException(
                'Gemini returned invalid or unparseable JSON response.',
                $resp['statusCode'],
                null,
                'gemini'
            );
        }

        // Check for prompt-level safety blocks
        if (!empty($data['promptFeedback']['blockReason'])) {
            $reason = (string) $data['promptFeedback']['blockReason'];
            throw new ProviderSafetyBlockException(
                "Gemini prompt blocked by safety policy ({$reason}).",
                $reason,
                null,
                'gemini'
            );
        }

        // Check candidates existence
        if (empty($data['candidates']) || !is_array($data['candidates'])) {
            throw new ProviderSafetyBlockException(
                'Gemini returned empty candidates (blocked by safety filters or no output generated).',
                'EMPTY_CANDIDATES',
                null,
                'gemini'
            );
        }

        $candidate    = $data['candidates'][0];
        $finishReason = (string) ($candidate['finishReason'] ?? 'STOP');

        if (in_array($finishReason, ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII'], true)) {
            throw new ProviderSafetyBlockException(
                "Gemini output blocked by content safety policy ({$finishReason}).",
                $finishReason,
                null,
                'gemini'
            );
        }

        // Extract text from parts
        $textParts = [];
        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (isset($part['text'])) {
                $textParts[] = (string) $part['text'];
            }
        }
        $generatedText = implode('', $textParts);

        // Distinct check for empty output due to token budget limit (MAX_TOKENS / LENGTH)
        if ($generatedText === '' && in_array($finishReason, ['MAX_TOKENS', 'LENGTH'], true)) {
            throw new ProviderInvalidResponseException(
                "Gemini output reached max token limit ({$finishReason}) before producing text. Increase max tokens in settings.",
                $resp['statusCode'],
                null,
                'gemini'
            );
        }

        // Usage counts
        $usage     = $data['usageMetadata'] ?? [];
        $tokensIn  = (int) ($usage['promptTokenCount'] ?? 0);
        $tokensOut = (int) ($usage['candidatesTokenCount'] ?? 0);

        return new ProviderResponse(
            text: $generatedText,
            tokensIn: $tokensIn,
            tokensOut: $tokensOut,
            model: $this->model,
            raw: [
                'finish_reason'  => $finishReason,
                'prompt_tokens'  => $tokensIn,
                'output_tokens'  => $tokensOut,
                'total_tokens'   => $tokensIn + $tokensOut,
            ]
        );
    }

    /**
     * Performs lightweight read-only test using GET /v1beta/models.
     * Consumes 0 tokens.
     */
    public function testConnection(): array
    {
        $start = microtime(true);
        $url   = $this->baseUrl . '/v1beta/models';

        $headers = [
            'x-goog-api-key' => $this->apiKey,
        ];

        try {
            $resp = $this->executeRequest($url, 'GET', $headers, null, 10);
            $latencyMs = (int) round((microtime(true) - $start) * 1000);

            $data = json_decode($resp['body'], true);
            $models = [];
            if (is_array($data) && !empty($data['models'])) {
                foreach ($data['models'] as $m) {
                    if (isset($m['name'])) {
                        // Strip 'models/' prefix if present
                        $models[] = str_replace('models/', '', (string) $m['name']);
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
