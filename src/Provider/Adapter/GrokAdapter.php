<?php

/**
 * GrokAdapter — Adapter for xAI Grok models.
 *
 * Official Reference: https://docs.x.ai/developers/rest-api-reference/inference
 *
 * xAI exposes a fully OpenAI-compatible Chat Completions surface
 * (POST /v1/chat/completions, Authorization: Bearer, GET /v1/models), so this
 * adapter extends OpenAiAdapter instead of duplicating its request building,
 * SSRF-validated base URL handling, cURL hardening and response parsing.
 *
 * Only two things differ from OpenAI and therefore need overriding here:
 *   1. The default endpoint (https://api.x.ai/v1).
 *   2. The provider identifier, which is persisted in the audit trail and
 *      rendered in the settings UI.
 *
 * Note: xAI currently designates the Responses API (/v1/responses) as the
 * recommended endpoint and marks Chat Completions as legacy-but-served. Chat
 * Completions is used here because it is the shape the whole provider layer
 * already speaks, keeping this module on a single stable contract.
 *
 * Compatibility: OpenEMR 8.2.0+ (PHP 8.2 compatible).
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\AiAssistant\Provider\Adapter;

class GrokAdapter extends OpenAiAdapter
{
    public const DEFAULT_BASE_URL = 'https://api.x.ai/v1';
    public const DEFAULT_MODEL    = 'grok-4.7';

    public function __construct(
        string $apiKey,
        string $model = self::DEFAULT_MODEL,
        float $temperature = 0.2,
        int $maxTokens = 2048,
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
        return 'grok';
    }
}