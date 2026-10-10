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
 *   2. The default model (glm-4-flash, free tier).
 *   3. The provider identifier, persisted in the audit trail and rendered in settings.
 *   4. testConnection(): Z.ai does not document a GET /models endpoint, so the
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

class ZaiAdapter extends OpenAiAdapter
{
    public const DEFAULT_BASE_URL = 'https://api.z.ai/api/paas/v4';
    public const DEFAULT_MODEL    = 'glm-4-flash';

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

            return [
                'ok'          => false,
                'latency_ms'  => $latencyMs,
                'status_code' => (int) $e->getCode(),
                'error'       => $e->getMessage(),
            ];
        }
    }
}
