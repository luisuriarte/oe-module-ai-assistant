<?php

/**
 * ZaiAdapter — Adapter for Z.ai (Zhipu AI) GLM models.
 *
 * Official Reference: https://docs.z.ai/guides/develop/http/introduction
 *
 * Z.ai exposes a fully OpenAI-compatible Chat Completions surface
 * (POST /paas/v4/chat/completions, Authorization: Bearer), so this adapter
 * extends OpenAiAdapter instead of duplicating its request building,
 * SSRF-validated base URL handling, cURL hardening and response parsing.
 *
 * What differs from OpenAI and is overridden here:
 *   1. The default endpoint (https://api.z.ai/api/paas/v4).
 *   2. The default model (glm-4.5-flash, free tier).
 *   3. The provider identifier, persisted in the audit trail and rendered in settings.
 *   4. The top-level "thinking" parameter is always sent disabled: GLM models are hybrid
 *      reasoning models, so with thinking on they can spend the (small) output budget on
 *      "reasoning_content" before emitting the JSON answer. If the endpoint rejects the
 *      parameter, the request is retried exactly once without it (see generate()).
 *   5. Empty visible content is diagnosed from metadata only; a token-limit finish reason
 *      raises a clear invalid-response error instead of a generic invalid-JSON failure.
 *   6. testConnection(): Z.ai does not document a GET /models endpoint, so the
 *      inherited OpenAI probe is tried first and, when unavailable, a minimal
 *      one-token chat completion validates the credential against the endpoint
 *      that actually serves inference.
 *
 * API keys use the "<id>.<secret>" form and are sent verbatim as a Bearer token.
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

class ZaiAdapter extends OpenAiAdapter
{
    public const DEFAULT_BASE_URL = 'https://api.z.ai/api/paas/v4';
    public const DEFAULT_MODEL    = 'glm-4.5-flash';

    /**
     * Top-level request parameter that controls GLM deep-thinking mode.
     * Reference: https://docs.z.ai/api-reference/llm/chat-completion
     */
    public const THINKING_PARAM = 'thinking';

    /**
     * Payload for THINKING_PARAM asking the endpoint to skip "reasoning_content".
     *
     * GLM models are hybrid reasoning models: with thinking enabled they can spend part of
     * the (small) output budget on reasoning text before producing any visible answer,
     * which for a strict-JSON SOAP draft yields an empty or truncated result and a wasted
     * correction retry. Disabling it keeps the whole budget for the answer.
     */
    public const THINKING_DISABLED = ['type' => 'disabled'];

    /**
     * Stable prefix of the empty-answer token-limit error. Kept as a constant so the
     * connection probe can recognise exactly this case (and only this case) without
     * masking other 200-level parse failures.
     */
    private const TOKEN_LIMIT_MESSAGE = 'Z.ai exhausted its output token budget before producing visible text';

    public function __construct(
        string $apiKey,
        string $model = self::DEFAULT_MODEL,
        float $temperature = 0.2,
        int $maxTokens = 4096,
        string $baseUrl = self::DEFAULT_BASE_URL,
        int $timeoutSec = 30,
        bool $allowPrivate = false
    ) {
        parent::__construct(
            $apiKey,
            $model ?: self::DEFAULT_MODEL,
            $temperature,
            $maxTokens,
            $baseUrl ?: self::DEFAULT_BASE_URL,
            $timeoutSec,
            $allowPrivate
        );
    }

    public function getProviderName(): string
    {
        return 'zai';
    }

    /**
     * Adds the top-level "thinking" parameter (disabled) to the inherited payload.
     */
    protected function buildChatPayload(array $messages, array $options): array
    {
        $payload = parent::buildChatPayload($messages, $options);
        $payload[self::THINKING_PARAM] = self::THINKING_DISABLED;

        return $payload;
    }

    /**
     * Generates content, with a one-shot fallback if the endpoint rejects "thinking".
     *
     * GLM models are hybrid reasoning models, so thinking is disabled by default (see
     * THINKING_DISABLED). Some deployments do not accept the parameter at all: if the API
     * answers with a 400 that clearly blames "thinking", the request is retried exactly
     * once without it. Any other error is rethrown unchanged. Never loops.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed>                             $options
     * @throws ProviderException
     */
    public function generate(array $messages, array $options = []): ProviderResponse
    {
        try {
            return parent::generate($messages, $options);
        } catch (ProviderInvalidResponseException $e) {
            if (!$this->thinkingWasRejected($e)) {
                throw $e;
            }

            $this->logger?->warning(
                '[AiAssistant] Z.ai rejected the thinking parameter; retrying once without it.',
                [
                    'provider'    => $this->getProviderName(),
                    'model'       => $this->model,
                    'http_status' => $e->getCode(),
                ]
            );

            // Exactly one retry, using the inherited payload builder (no thinking key).
            return $this->sendChatCompletion(parent::buildChatPayload($messages, $options), $options);
        }
    }

    /**
     * Identifies the request field Z.ai blamed, from the 4xx error body.
     *
     * Only the short field name is returned; the provider message is inspected to classify
     * the error but never stored or logged. On HTTP 400 it returns "thinking" only when the
     * message names that word (e.g. the documented code 1214, "Parameter `thinking` is
     * invalid") — the only optional field this adapter adds beyond the OpenAI-compatible
     * set. Every other 400, including one about a different parameter, returns null so it
     * cannot trigger a wasted retry or a misleading rejection warning.
     */
    protected function rejectedFieldFromErrorBody(string $body, int $statusCode): ?string
    {
        if ($statusCode !== 400) {
            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['error']) || !is_array($decoded['error'])) {
            return null;
        }

        $message = strtolower((string) ($decoded['error']['message'] ?? ''));
        if ($message === '') {
            return null;
        }

        if (str_contains($message, self::THINKING_PARAM)) {
            return self::THINKING_PARAM;
        }

        return null;
    }

    /**
     * Diagnoses a response with no visible text, using metadata only.
     *
     * The visible content and the reasoning text are NEVER logged. When the model ran out
     * of output tokens (finish_reason "length") this raises the same invalid-response
     * category used for MAX_TOKENS, with a clear message, so the caller reports a token
     * exhaustion instead of a generic invalid-JSON failure.
     *
     * @param array<string, mixed> $message the choices[0].message object
     */
    protected function afterChatCompletionParsed(string $text, string $finishReason, array $message, int $statusCode): void
    {
        if ($text !== '') {
            return;
        }

        $reasoningPresent = trim((string) ($message['reasoning_content'] ?? '')) !== '';

        $this->logger?->warning(
            '[AiAssistant] Z.ai returned no visible content.',
            $this->emptyCompletionContext($finishReason, $reasoningPresent)
        );

        if (strtolower($finishReason) === 'length') {
            throw new ProviderInvalidResponseException(
                self::TOKEN_LIMIT_MESSAGE . ' '
                    . "(finish_reason=length, reasoning_content_present="
                    . ($reasoningPresent ? 'true' : 'false')
                    . '). The output budget is set by the calling feature (SOAP draft or chat), '
                    . 'and thinking is already disabled for Z.ai by default.',
                $statusCode,
                null,
                $this->getProviderName()
            );
        }
    }

    /**
     * Metadata-only log context for empty-answer diagnostics (no content, no reasoning
     * text). Extracted so it can be asserted without a live logger.
     *
     * @return array{provider: string, model: string, finish_reason: string, reasoning_content_present: bool}
     */
    protected function emptyCompletionContext(string $finishReason, bool $reasoningContentPresent): array
    {
        return [
            'provider'                  => $this->getProviderName(),
            'model'                     => $this->model,
            'finish_reason'             => $finishReason,
            'reasoning_content_present' => $reasoningContentPresent,
        ];
    }

    private function thinkingWasRejected(ProviderInvalidResponseException $e): bool
    {
        return $e->getCode() === 400
            && str_contains($e->errorDetail(), 'param=' . self::THINKING_PARAM);
    }

    /**
     * Probes the Z.ai account.
     *
     * The inherited OpenAI probe (GET /models, zero token cost) is attempted first.
     * Z.ai does not document a models endpoint, so a non-2xx there says nothing
     * about the key: fall back to a minimal, one-token chat completion against the
     * configured model, which is the endpoint that actually serves inference.
     */
    public function testConnection(): array
    {
        $probe = parent::testConnection();
        if (!empty($probe['ok'])) {
            return $probe;
        }

        $start = microtime(true);

        try {
            $this->generate(
                [['role' => 'user', 'content' => 'ping']],
                ['max_tokens' => 1, 'temperature' => 0.0, 'timeout' => 10]
            );
            $latencyMs = (int) round((microtime(true) - $start) * 1000);

            return [
                'ok'          => true,
                'latency_ms'  => $latencyMs,
                'status_code' => 200,
                'error'       => '',
                'models'      => [$this->model],
            ];
        } catch (\Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $start) * 1000);

            // The probe caps output at one token on purpose. If the endpoint answered HTTP
            // 200 but spent that single token without visible text (e.g. a reasoning model
            // that ignores thinking=disabled), the credential is still valid: report success
            // rather than turning the diagnostics from afterChatCompletionParsed() into a
            // false "connection failed". Only this specific case is treated as success; any
            // other 200-level parse failure still fails the probe.
            if (
                $e instanceof ProviderInvalidResponseException
                && $e->getCode() === 200
                && str_starts_with($e->getMessage(), self::TOKEN_LIMIT_MESSAGE)
            ) {
                return [
                    'ok'          => true,
                    'latency_ms'  => $latencyMs,
                    'status_code' => 200,
                    'error'       => '',
                    'models'      => [$this->model],
                ];
            }

            return [
                'ok'          => false,
                'latency_ms'  => $latencyMs,
                'status_code' => (int) $e->getCode(),
                'error'       => $e->getMessage(),
            ];
        }
    }
}
