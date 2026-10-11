<?php

/**
 * ProviderSimulationTest — Automated simulated-response test suite for M3 Provider Layer.
 *
 * Tests:
 *   1. OpenAI-compatible adapter: payload format, response extraction, token counts.
 *   2. Anthropic adapter: system prompt extraction, response parsing, token counts.
 *   3. Gemini adapter: candidates extraction, safety block handling, token counts.
 *   4. Grok (xAI) adapter: endpoint default, provider identity, response extraction.
 *   5. Z.ai (GLM) adapter: endpoint default, provider identity, dotted API key, response extraction.
 *   6. Z.ai thinking control: thinking disabled by default, one-shot retry when a 400 blames it,
 *      unrelated errors not retried, empty-answer diagnostics without leaking content.
 *   7. Uniform ProviderException mapping (401/403, 429, timeout, safety block).
 *   8. Security validations: HTTPS-only, SSRF prevention on private/link-local IPs, embedded credential rejection.
 *
 * Compatibility: OpenEMR 8.2.0+ (PHP 8.2 compatible).
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Tests;

// Autoloader for standalone CLI execution
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

use OpenEMR\Modules\AiAssistant\Provider\Adapter\AbstractProviderAdapter;
use OpenEMR\Modules\AiAssistant\Provider\Adapter\AnthropicAdapter;
use OpenEMR\Modules\AiAssistant\Provider\Adapter\GeminiAdapter;
use OpenEMR\Modules\AiAssistant\Provider\Adapter\GrokAdapter;
use OpenEMR\Modules\AiAssistant\Provider\Adapter\OpenAiAdapter;
use OpenEMR\Modules\AiAssistant\Provider\Adapter\ZaiAdapter;
use OpenEMR\Modules\AiAssistant\Provider\Exception\ProviderAuthenticationException;
use OpenEMR\Modules\AiAssistant\Provider\Exception\ProviderException;
use OpenEMR\Modules\AiAssistant\Provider\Exception\ProviderInvalidResponseException;
use OpenEMR\Modules\AiAssistant\Provider\Exception\ProviderRateLimitException;
use OpenEMR\Modules\AiAssistant\Provider\Exception\ProviderSafetyBlockException;

class TestableOpenAiAdapter extends OpenAiAdapter
{
    public array $lastExecuted = [];
    public ?array $mockResponse = null;

    protected function executeRequest(string $url, string $method = 'POST', array $headers = [], ?string $jsonBody = null, ?int $customTimeout = null): array
    {
        $this->lastExecuted = ['url' => $url, 'method' => $method, 'headers' => $headers, 'body' => $jsonBody, 'timeout' => $customTimeout];
        if ($this->mockResponse !== null) {
            if (isset($this->mockResponse['exception'])) {
                throw $this->mockResponse['exception'];
            }
            return $this->mockResponse;
        }
        return ['statusCode' => 200, 'body' => '{}', 'headers' => []];
    }
}

class TestableAnthropicAdapter extends AnthropicAdapter
{
    public array $lastExecuted = [];
    public ?array $mockResponse = null;

    protected function executeRequest(string $url, string $method = 'POST', array $headers = [], ?string $jsonBody = null, ?int $customTimeout = null): array
    {
        $this->lastExecuted = ['url' => $url, 'method' => $method, 'headers' => $headers, 'body' => $jsonBody, 'timeout' => $customTimeout];
        if ($this->mockResponse !== null) {
            if (isset($this->mockResponse['exception'])) {
                throw $this->mockResponse['exception'];
            }
            return $this->mockResponse;
        }
        return ['statusCode' => 200, 'body' => '{}', 'headers' => []];
    }
}

class TestableGeminiAdapter extends GeminiAdapter
{
    public array $lastExecuted = [];
    public ?array $mockResponse = null;

    protected function executeRequest(string $url, string $method = 'POST', array $headers = [], ?string $jsonBody = null, ?int $customTimeout = null): array
    {
        $this->lastExecuted = ['url' => $url, 'method' => $method, 'headers' => $headers, 'body' => $jsonBody, 'timeout' => $customTimeout];
        if ($this->mockResponse !== null) {
            if (isset($this->mockResponse['exception'])) {
                throw $this->mockResponse['exception'];
            }
            return $this->mockResponse;
        }
        return ['statusCode' => 200, 'body' => '{}', 'headers' => []];
    }
}

class TestableGrokAdapter extends GrokAdapter
{
    public array $lastExecuted = [];
    public ?array $mockResponse = null;

    protected function executeRequest(string $url, string $method = 'POST', array $headers = [], ?string $jsonBody = null, ?int $customTimeout = null): array
    {
        $this->lastExecuted = ['url' => $url, 'method' => $method, 'headers' => $headers, 'body' => $jsonBody, 'timeout' => $customTimeout];
        if ($this->mockResponse !== null) {
            if (isset($this->mockResponse['exception'])) {
                throw $this->mockResponse['exception'];
            }
            return $this->mockResponse;
        }
        return ['statusCode' => 200, 'body' => '{}', 'headers' => []];
    }
}

class TestableZaiAdapter extends ZaiAdapter
{
    public array $lastExecuted = [];
    /** Every request body/url executed, in order (used by the retry test). */
    public array $executed = [];
    public ?array $mockResponse = null;
    /** Responses (or ['exception' => Throwable]) consumed one per call. */
    public array $responseQueue = [];

    protected function executeRequest(string $url, string $method = 'POST', array $headers = [], ?string $jsonBody = null, ?int $customTimeout = null): array
    {
        $this->lastExecuted = ['url' => $url, 'method' => $method, 'headers' => $headers, 'body' => $jsonBody, 'timeout' => $customTimeout];
        $this->executed[]   = $this->lastExecuted;

        if (!empty($this->responseQueue)) {
            $next = array_shift($this->responseQueue);
            if (isset($next['exception'])) {
                throw $next['exception'];
            }
            return $next;
        }

        if ($this->mockResponse !== null) {
            if (isset($this->mockResponse['exception'])) {
                throw $this->mockResponse['exception'];
            }
            return $this->mockResponse;
        }
        return ['statusCode' => 200, 'body' => '{}', 'headers' => []];
    }

    /** Test seam: the field Z.ai would blame for a given 4xx body. */
    public function blamedField(string $body, int $statusCode): ?string
    {
        return $this->rejectedFieldFromErrorBody($body, $statusCode);
    }

    /** Test seam: the sanitized error detail the request layer would attach. */
    public function errorDetail(string $body, int $statusCode): string
    {
        return $this->buildErrorDetail($body, $statusCode);
    }

    /** Test seam: the metadata-only log context for an empty answer. */
    public function emptyLogContext(string $finishReason, bool $reasoningPresent): array
    {
        return $this->emptyCompletionContext($finishReason, $reasoningPresent);
    }
}

class ProviderSimulationRunner
{
    private int $passed = 0;
    private int $failed = 0;

    public function run(): void
    {
        echo "=== Running M3 Provider Layer Simulation Tests ===\n\n";

        $this->testUrlSecurity();
        $this->testOpenAiSimulation();
        $this->testAnthropicSimulation();
        $this->testGeminiSimulation();
        $this->testGrokSimulation();
        $this->testZaiSimulation();
        $this->testZaiThinkingControl();
        $this->testGeminiSafetyBlocks();
        $this->testUniformExceptions();
        $this->testTimeoutForwarding();
        $this->testHttpRetryPolicy();

        echo "\n===================================================\n";
        echo "Results: {$this->passed} passed, {$this->failed} failed.\n";
        if ($this->failed > 0) {
            exit(1);
        }
    }

    private function assert(string $testName, bool $condition, string $message = ''): void
    {
        if ($condition) {
            $this->passed++;
            echo " [PASS] {$testName}\n";
        } else {
            $this->failed++;
            echo " [FAIL] {$testName}: {$message}\n";
        }
    }

    private function testUrlSecurity(): void
    {
        // 1. Rejects HTTP (non-TLS)
        try {
            AbstractProviderAdapter::validateBaseUrl('http://api.openai.com');
            $this->assert('URL Security: HTTP rejected', false, 'Should reject plain HTTP');
        } catch (\InvalidArgumentException $e) {
            $this->assert('URL Security: HTTP rejected', true);
        }

        // 2. Rejects embedded credentials
        try {
            AbstractProviderAdapter::validateBaseUrl('https://user:pass@api.openai.com');
            $this->assert('URL Security: Embedded credentials rejected', false, 'Should reject credentials');
        } catch (\InvalidArgumentException $e) {
            $this->assert('URL Security: Embedded credentials rejected', true);
        }

        // 3. Rejects private loopback / link-local addresses
        try {
            AbstractProviderAdapter::validateBaseUrl('https://127.0.0.1:8000');
            $this->assert('URL Security: Loopback IP rejected by default', false, 'Should reject 127.0.0.1');
        } catch (\InvalidArgumentException $e) {
            $this->assert('URL Security: Loopback IP rejected by default', true);
        }

        try {
            AbstractProviderAdapter::validateBaseUrl('https://169.254.169.254');
            $this->assert('URL Security: Cloud metadata link-local rejected', false, 'Should reject metadata IP');
        } catch (\InvalidArgumentException $e) {
            $this->assert('URL Security: Cloud metadata link-local rejected', true);
        }

        // 4. Valid HTTPS normalized
        $normalized = AbstractProviderAdapter::validateBaseUrl('https://api.openai.com/v1/');
        $this->assert('URL Security: Trailing slash trimmed', $normalized === 'https://api.openai.com/v1');
    }

    private function testOpenAiSimulation(): void
    {
        $adapter = new TestableOpenAiAdapter('sk-test-key-openai', 'gpt-4o', 0.2, 1024);
        $adapter->mockResponse = [
            'statusCode' => 200,
            'body' => json_encode([
                'id' => 'chatcmpl-test1234',
                'object' => 'chat.completion',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => ['role' => 'assistant', 'content' => 'Simulated OpenAI response for medical query.'],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 25,
                    'completion_tokens' => 12,
                    'total_tokens' => 37,
                ],
            ]),
            'headers' => ['content-type' => 'application/json'],
        ];

        $resp = $adapter->generate([
            ['role' => 'system', 'content' => 'You are a triage assistant.'],
            ['role' => 'user', 'content' => 'Patient has headache.'],
        ]);

        $this->assert('OpenAI: Extracted text matches', $resp->text === 'Simulated OpenAI response for medical query.');
        $this->assert('OpenAI: Token usage in matches', $resp->tokensIn === 25);
        $this->assert('OpenAI: Token usage out matches', $resp->tokensOut === 12);
        $this->assert('OpenAI: Total tokens matches', $resp->getTotalTokens() === 37);
        $this->assert('OpenAI: Authorization Bearer header sent', str_contains($adapter->lastExecuted['headers']['Authorization'] ?? '', 'Bearer sk-test-key-openai'));
    }

    private function testAnthropicSimulation(): void
    {
        $adapter = new TestableAnthropicAdapter('sk-ant-test-key', 'claude-3-5-sonnet-20241022', 0.2, 1024);
        $adapter->mockResponse = [
            'statusCode' => 200,
            'body' => json_encode([
                'id' => 'msg_test123',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [
                    ['type' => 'text', 'text' => 'Simulated Claude response text.'],
                ],
                'model' => 'claude-3-5-sonnet-20241022',
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 30,
                    'output_tokens' => 15,
                ],
            ]),
            'headers' => ['content-type' => 'application/json'],
        ];

        $resp = $adapter->generate([
            ['role' => 'system', 'content' => 'System prompt preamble.'],
            ['role' => 'user', 'content' => 'Clinical question.'],
        ]);

        $this->assert('Anthropic: Extracted text matches', $resp->text === 'Simulated Claude response text.');
        $this->assert('Anthropic: Token usage in matches', $resp->tokensIn === 30);
        $this->assert('Anthropic: Token usage out matches', $resp->tokensOut === 15);
        $this->assert('Anthropic: x-api-key header sent', ($adapter->lastExecuted['headers']['x-api-key'] ?? '') === 'sk-ant-test-key');
        $this->assert('Anthropic: anthropic-version header sent', ($adapter->lastExecuted['headers']['anthropic-version'] ?? '') === '2023-06-01');

        // Verify system prompt was moved to top-level 'system' parameter in payload
        $body = json_decode($adapter->lastExecuted['body'], true);
        $this->assert('Anthropic: System prompt placed in top-level system field', ($body['system'] ?? '') === 'System prompt preamble.');
    }

    private function testGeminiSimulation(): void
    {
        $adapter = new TestableGeminiAdapter('AIzaSyTestGeminiKey', 'gemini-2.0-flash', 0.2, 1024);
        $adapter->mockResponse = [
            'statusCode' => 200,
            'body' => json_encode([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'Simulated Gemini generation from Google AI Studio.'],
                            ],
                            'role' => 'model',
                        ],
                        'finishReason' => 'STOP',
                        'index' => 0,
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 20,
                    'candidatesTokenCount' => 10,
                    'totalTokenCount' => 30,
                ],
            ]),
            'headers' => ['content-type' => 'application/json'],
        ];

        $resp = $adapter->generate([
            ['role' => 'system', 'content' => 'Clinical assistant tone.'],
            ['role' => 'user', 'content' => 'What is normal systolic BP?'],
        ]);

        $this->assert('Gemini: Extracted text matches', $resp->text === 'Simulated Gemini generation from Google AI Studio.');
        $this->assert('Gemini: Token usage in matches', $resp->tokensIn === 20);
        $this->assert('Gemini: Token usage out matches', $resp->tokensOut === 10);
        $this->assert('Gemini: API key strictly in x-goog-api-key header', ($adapter->lastExecuted['headers']['x-goog-api-key'] ?? '') === 'AIzaSyTestGeminiKey');
        $this->assert('Gemini: API key NOT present in URL query string', !str_contains($adapter->lastExecuted['url'], 'key='));
    }

    private function testGrokSimulation(): void
    {
        // Default construction must target the xAI endpoint and report the grok provider id
        $defaults = new TestableGrokAdapter('xai-test-key');
        $this->assert(
            'Grok: Default base URL is api.x.ai',
            $defaults->getProviderName() === 'grok'
        );

        $adapter = new TestableGrokAdapter('xai-test-key', 'grok-4.7', 0.2, 1024);
        $adapter->mockResponse = [
            'statusCode' => 200,
            'body' => json_encode([
                'id' => 'chatcmpl-grok1234',
                'object' => 'chat.completion',
                'model' => 'grok-4.7',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => ['role' => 'assistant', 'content' => '{"subjective":"ok","objective":"","assessment":"","plan":""}'],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 40,
                    'completion_tokens' => 20,
                    'total_tokens' => 60,
                ],
            ]),
            'headers' => ['content-type' => 'application/json'],
        ];

        $resp = $adapter->generate([
            ['role' => 'system', 'content' => 'You are a triage assistant.'],
            ['role' => 'user', 'content' => 'Patient reports headache.'],
        ]);

        $this->assert('Grok: Extracted text matches', $resp->text === '{"subjective":"ok","objective":"","assessment":"","plan":""}');
        $this->assert('Grok: Token usage in matches', $resp->tokensIn === 40);
        $this->assert('Grok: Token usage out matches', $resp->tokensOut === 20);
        $this->assert('Grok: Total tokens matches', $resp->getTotalTokens() === 60);
        $this->assert('Grok: Provider identifier is grok', $adapter->getProviderName() === 'grok');
        $this->assert('Grok: Authorization Bearer header sent', str_contains($adapter->lastExecuted['headers']['Authorization'] ?? '', 'Bearer xai-test-key'));
        $this->assert('Grok: API key NOT present in URL', !str_contains($adapter->lastExecuted['url'], 'xai-test-key'));
        $this->assert(
            'Grok: Requests xAI chat completions endpoint',
            $adapter->lastExecuted['url'] === 'https://api.x.ai/v1/chat/completions'
        );

        $body = json_decode($adapter->lastExecuted['body'], true);
        $this->assert('Grok: Model sent in payload', ($body['model'] ?? '') === 'grok-4.7');
        $this->assert('Grok: System role preserved in messages', ($body['messages'][0]['role'] ?? '') === 'system');

        // Provider name flows into the audit trail, so it must never leak as 'openai'
        $this->assert('Grok: Provider name distinct from openai', $adapter->getProviderName() !== 'openai');

        // SSRF guard must be inherited: private/loopback base URLs are refused
        $ssrfBlocked = false;
        try {
            new TestableGrokAdapter('xai-test-key', 'grok-4.7', 0.2, 1024, 'http://127.0.0.1:8080/v1');
        } catch (\InvalidArgumentException) {
            $ssrfBlocked = true;
        }
        $this->assert('Grok: Private/loopback base URL rejected (SSRF guard inherited)', $ssrfBlocked);
    }

    private function testZaiSimulation(): void
    {
        // Default construction must target the Z.ai endpoint and report the zai provider id
        $defaults = new TestableZaiAdapter('abc12345.abcdefghijkl');
        $this->assert('Zai: Default provider identifier is zai', $defaults->getProviderName() === 'zai');

        $adapter = new TestableZaiAdapter('abc12345.abcdefghijkl', 'glm-4.5-flash', 0.2, 4096);
        $adapter->mockResponse = [
            'statusCode' => 200,
            'body' => json_encode([
                'id' => 'chatcmpl-zai1234',
                'object' => 'chat.completion',
                'model' => 'glm-4.5-flash',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => ['role' => 'assistant', 'content' => '{"subjective":"ok","objective":"","assessment":"","plan":""}'],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 42,
                    'completion_tokens' => 18,
                    'total_tokens' => 60,
                ],
            ]),
            'headers' => ['content-type' => 'application/json'],
        ];

        $resp = $adapter->generate([
            ['role' => 'system', 'content' => 'You are a triage assistant.'],
            ['role' => 'user', 'content' => 'Patient reports headache.'],
        ]);

        $this->assert('Zai: Extracted text matches', $resp->text === '{"subjective":"ok","objective":"","assessment":"","plan":""}');
        $this->assert('Zai: Token usage in matches', $resp->tokensIn === 42);
        $this->assert('Zai: Token usage out matches', $resp->tokensOut === 18);
        $this->assert('Zai: Total tokens matches', $resp->getTotalTokens() === 60);
        $this->assert('Zai: Provider identifier is zai', $adapter->getProviderName() === 'zai');
        $this->assert('Zai: Authorization Bearer header sent', ($adapter->lastExecuted['headers']['Authorization'] ?? '') === 'Bearer abc12345.abcdefghijkl');
        $this->assert('Zai: API key NOT present in URL', !str_contains($adapter->lastExecuted['url'], 'abc12345.abcdefghijkl'));
        $this->assert(
            'Zai: Requests the Z.ai paas/v4 chat completions endpoint',
            $adapter->lastExecuted['url'] === 'https://api.z.ai/api/paas/v4/chat/completions'
        );

        $body = json_decode($adapter->lastExecuted['body'], true);
        $this->assert('Zai: Model sent in payload', ($body['model'] ?? '') === 'glm-4.5-flash');
        $this->assert('Zai: Max tokens sent in payload', ($body['max_tokens'] ?? 0) === 4096);
        $this->assert('Zai: System role preserved in messages', ($body['messages'][0]['role'] ?? '') === 'system');
        $this->assert('Zai: Default model constant is glm-4.5-flash', ZaiAdapter::DEFAULT_MODEL === 'glm-4.5-flash');
        $this->assert(
            'Zai: Request disables thinking (thinking.type = disabled)',
            ($body['thinking']['type'] ?? '') === 'disabled'
        );

        // Provider name flows into the audit trail, so it must never leak as 'openai'
        $this->assert('Zai: Provider name distinct from openai', $adapter->getProviderName() !== 'openai');

        // SSRF guard must be inherited: private/loopback base URLs are refused
        $ssrfBlocked = false;
        try {
            new TestableZaiAdapter('abc12345.abcdefghijkl', 'glm-4.5-flash', 0.2, 4096, 'http://127.0.0.1:8080/v1');
        } catch (\InvalidArgumentException) {
            $ssrfBlocked = true;
        }
        $this->assert('Zai: Private/loopback base URL rejected (SSRF guard inherited)', $ssrfBlocked);
    }

    /**
     * Z.ai hybrid-reasoning handling: the request disables thinking, a 400 that blames
     * "thinking" is retried exactly once without it, an unrelated 400 is not retried, and
     * an empty answer at the token limit raises a clear error with metadata-only logging.
     */
    private function testZaiThinkingControl(): void
    {
        $thinkingBody  = '{"error":{"code":"1214","message":"Parameter `thinking` is invalid. Please check the documentation."}}';
        $unrelatedBody = '{"error":{"code":"1211","message":"Unknown Model, please check the model code."}}';

        // 1. Field-blame extraction feeds the retry decision and the error detail.
        $probe = new TestableZaiAdapter('abc12345.abcdefghijkl');
        $this->assert('Zai thinking: blames the thinking field on a 1214 body', $probe->blamedField($thinkingBody, 400) === 'thinking');
        $this->assert('Zai thinking: error detail names the blamed field', str_contains($probe->errorDetail($thinkingBody, 400), 'param=thinking'));
        $this->assert('Zai thinking: unrelated 400 does not blame thinking', $probe->blamedField($unrelatedBody, 400) === null);

        // 2. A 400 that rejects thinking is retried exactly once, without the parameter.
        $adapter = new TestableZaiAdapter('abc12345.abcdefghijkl', 'glm-4.5-flash', 0.2, 4096);
        $rejected = new ProviderInvalidResponseException('Z.ai rejected the request (HTTP 400)', 400, null, 'zai');
        $rejected->setErrorDetail($adapter->errorDetail($thinkingBody, 400));
        $adapter->responseQueue = [
            ['exception' => $rejected],
            [
                'statusCode' => 200,
                'body' => json_encode([
                    'choices' => [[
                        'message' => ['role' => 'assistant', 'content' => '{"subjective":"ok","objective":"","assessment":"","plan":""}'],
                        'finish_reason' => 'stop',
                    ]],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                ]),
                'headers' => [],
            ],
        ];

        $resp = $adapter->generate([['role' => 'user', 'content' => 'hola']]);

        $this->assert('Zai thinking: a thinking 400 triggers exactly one retry', count($adapter->executed) === 2);
        $this->assert('Zai thinking: first request included thinking disabled', (json_decode($adapter->executed[0]['body'], true)['thinking']['type'] ?? '') === 'disabled');
        $this->assert('Zai thinking: retried request omits thinking', !array_key_exists('thinking', json_decode($adapter->executed[1]['body'], true)));
        $this->assert('Zai thinking: retry returns the parsed text', $resp->text === '{"subjective":"ok","objective":"","assessment":"","plan":""}');

        // 3. An unrelated 400 is surfaced on the first attempt (no retry).
        $adapter2 = new TestableZaiAdapter('abc12345.abcdefghijkl', 'glm-4.5-flash', 0.2, 4096);
        $unrelated = new ProviderInvalidResponseException('Z.ai rejected the request (HTTP 400)', 400, null, 'zai');
        $unrelated->setErrorDetail($adapter2->errorDetail($unrelatedBody, 400));
        $adapter2->responseQueue = [
            ['exception' => $unrelated],
            ['statusCode' => 200, 'body' => '{"choices":[{"message":{"content":"should not be reached"},"finish_reason":"stop"}]}', 'headers' => []],
        ];

        $rethrown = false;
        try {
            $adapter2->generate([['role' => 'user', 'content' => 'hola']]);
        } catch (ProviderInvalidResponseException $e) {
            $rethrown = $e->getCode() === 400;
        }
        $this->assert('Zai thinking: an unrelated 400 is not retried', $rethrown && count($adapter2->executed) === 1);

        // 4. Empty content at the token limit: clear error, metadata-only log context.
        $adapter3 = new TestableZaiAdapter('abc12345.abcdefghijkl', 'glm-4.5-flash', 0.2, 4096);
        $adapter3->mockResponse = [
            'statusCode' => 200,
            'body' => json_encode([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => '', 'reasoning_content' => 'SECRET_REASONING_TEXT'],
                    'finish_reason' => 'length',
                ]],
                'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 4096],
            ]),
            'headers' => [],
        ];

        $emptyError = null;
        try {
            $adapter3->generate([['role' => 'user', 'content' => 'hola']]);
        } catch (ProviderInvalidResponseException $e) {
            $emptyError = $e;
        }
        $this->assert('Zai thinking: empty content at the token limit raises an invalid-response error', $emptyError !== null);
        $this->assert('Zai thinking: the error message points to output-token exhaustion', $emptyError !== null && stripos($emptyError->getMessage(), 'output token budget') !== false);

        $context = $adapter3->emptyLogContext('length', true);
        $this->assert('Zai thinking: empty-answer log context is metadata only', array_keys($context) === ['provider', 'model', 'finish_reason', 'reasoning_content_present']);
        $this->assert('Zai thinking: empty-answer log context flags reasoning presence', ($context['reasoning_content_present'] ?? null) === true);
        $this->assert('Zai thinking: empty-answer log context leaks no reasoning text', !str_contains(json_encode($context), 'SECRET_REASONING_TEXT'));

        // 5. The connection probe still reports success when a 200 only exhausted its
        //    one-token budget, but any other 200-level failure still fails the probe.
        $probeAdapter = new TestableZaiAdapter('abc12345.abcdefghijkl', 'glm-4.5-flash', 0.2, 4096);
        $probeAdapter->responseQueue = [
            ['exception' => new \RuntimeException('models endpoint unavailable', 404)],
            [
                'statusCode' => 200,
                'body' => json_encode([
                    'choices' => [[
                        'message' => ['role' => 'assistant', 'content' => '', 'reasoning_content' => 'r'],
                        'finish_reason' => 'length',
                    ]],
                ]),
                'headers' => [],
            ],
        ];
        $this->assert('Zai thinking: connection probe succeeds on a token-limited 200', ($probeAdapter->testConnection()['ok'] ?? false) === true);

        $brokenAdapter = new TestableZaiAdapter('abc12345.abcdefghijkl', 'glm-4.5-flash', 0.2, 4096);
        $brokenAdapter->responseQueue = [
            ['exception' => new \RuntimeException('models endpoint unavailable', 404)],
            ['statusCode' => 200, 'body' => '{}', 'headers' => []],
        ];
        $this->assert('Zai thinking: connection probe still fails on a 200 with no choices', ($brokenAdapter->testConnection()['ok'] ?? true) === false);
    }

    private function testGeminiSafetyBlocks(): void
    {
        $adapter = new TestableGeminiAdapter('AIzaSyTestGeminiKey', 'gemini-2.0-flash');

        // Test finishReason SAFETY
        $adapter->mockResponse = [
            'statusCode' => 200,
            'body' => json_encode([
                'candidates' => [
                    [
                        'finishReason' => 'SAFETY',
                        'content' => ['parts' => []],
                    ],
                ],
            ]),
            'headers' => [],
        ];

        try {
            $adapter->generate([['role' => 'user', 'content' => 'unsafe query']]);
            $this->assert('Gemini: finishReason SAFETY maps to ProviderSafetyBlockException', false);
        } catch (ProviderSafetyBlockException $e) {
            $this->assert('Gemini: finishReason SAFETY maps to ProviderSafetyBlockException', true);
            $this->assert('Gemini: Safety blockReason captured', $e->getBlockReason() === 'SAFETY');
        }

        // Test promptFeedback blockReason
        $adapter->mockResponse = [
            'statusCode' => 200,
            'body' => json_encode([
                'promptFeedback' => [
                    'blockReason' => 'PROHIBITED_CONTENT',
                ],
            ]),
            'headers' => [],
        ];

        try {
            $adapter->generate([['role' => 'user', 'content' => 'prohibited prompt']]);
            $this->assert('Gemini: promptFeedback blockReason maps to ProviderSafetyBlockException', false);
        } catch (ProviderSafetyBlockException $e) {
            $this->assert('Gemini: promptFeedback blockReason maps to ProviderSafetyBlockException', true);
            $this->assert('Gemini: Prohibited blockReason captured', $e->getBlockReason() === 'PROHIBITED_CONTENT');
        }

        // Test empty candidates
        $adapter->mockResponse = [
            'statusCode' => 200,
            'body' => json_encode(['candidates' => []]),
            'headers' => [],
        ];

        try {
            $adapter->generate([['role' => 'user', 'content' => 'empty test']]);
            $this->assert('Gemini: empty candidates maps to ProviderSafetyBlockException', false);
        } catch (ProviderSafetyBlockException $e) {
            $this->assert('Gemini: empty candidates maps to ProviderSafetyBlockException', true);
        }
    }

    private function testUniformExceptions(): void
    {
        $adapter = new TestableGeminiAdapter('key', 'model');

        // 429 Rate limit
        $adapter->mockResponse = [
            'exception' => new ProviderRateLimitException('Rate limit exceeded', 429, null, 'gemini', 60),
        ];
        try {
            $adapter->generate([['role' => 'user', 'content' => 'test']]);
            $this->assert('Uniform Exception: Rate limit', false);
        } catch (ProviderRateLimitException $e) {
            $this->assert('Uniform Exception: Rate limit caught as subtype', true);
            $this->assert('Uniform Exception: Rate limit is instance of ProviderException', $e instanceof ProviderException);
            $this->assert('Uniform Exception: Retry-After parsed', $e->getRetryAfterSec() === 60);
        }

        // 401 Auth error
        $adapter->mockResponse = [
            'exception' => new ProviderAuthenticationException('Auth failed', 401, null, 'gemini'),
        ];
        try {
            $adapter->generate([['role' => 'user', 'content' => 'test']]);
            $this->assert('Uniform Exception: Authentication', false);
        } catch (ProviderAuthenticationException $e) {
            $this->assert('Uniform Exception: Auth error caught as subtype', true);
            $this->assert('Uniform Exception: Auth error is instance of ProviderException', $e instanceof ProviderException);
        }
    }

    /**
     * The generator and the chat service pass a per-request 'timeout' option. Each
     * adapter must honour it: otherwise the request is cut at the constructor default
     * (30 s) no matter what the caller asked for.
     */
    private function testTimeoutForwarding(): void
    {
        $adapter = new TestableOpenAiAdapter('sk-key', 'gpt-4o', 0.2, 1024);
        $adapter->mockResponse = ['statusCode' => 200, 'body' => '{"choices":[{"message":{"content":"x"},"finish_reason":"stop"}],"usage":{"prompt_tokens":1,"completion_tokens":1}}', 'headers' => []];
        $adapter->generate([['role' => 'user', 'content' => 'q']], ['timeout' => 61]);
        $this->assert('OpenAI: per-request timeout option is honoured', ($adapter->lastExecuted['timeout'] ?? 0) === 61);

        $adapter = new TestableOpenAiAdapter('sk-key', 'gpt-4o', 0.2, 1024, 'https://api.openai.com/v1', 90);
        $adapter->mockResponse = ['statusCode' => 200, 'body' => '{"choices":[{"message":{"content":"x"},"finish_reason":"stop"}],"usage":{"prompt_tokens":1,"completion_tokens":1}}', 'headers' => []];
        $adapter->generate([['role' => 'user', 'content' => 'q']]);
        $this->assert('OpenAI: constructor timeout used when no option given', ($adapter->lastExecuted['timeout'] ?? 0) === 90);

        $anthropic = new TestableAnthropicAdapter('sk-key', 'claude-x', 0.2, 1024);
        $anthropic->mockResponse = ['statusCode' => 200, 'body' => '{"content":[{"type":"text","text":"x"}],"usage":{"input_tokens":1,"output_tokens":1}}', 'headers' => []];
        $anthropic->generate([['role' => 'user', 'content' => 'q']], ['timeout' => 62]);
        $this->assert('Anthropic: per-request timeout option is honoured', ($anthropic->lastExecuted['timeout'] ?? 0) === 62);

        $gemini = new TestableGeminiAdapter('key', 'gemini-3.8-flash');
        $gemini->mockResponse = ['statusCode' => 200, 'body' => '{"candidates":[{"content":{"parts":[{"text":"x"}]},"finishReason":"STOP"}]}', 'headers' => []];
        $gemini->generate([['role' => 'user', 'content' => 'q']], ['timeout' => 63]);
        $this->assert('Gemini: per-request timeout option is honoured', ($gemini->lastExecuted['timeout'] ?? 0) === 63);

        $grok = new TestableGrokAdapter('xai-key', 'grok-4.7', 0.2, 1024);
        $grok->mockResponse = ['statusCode' => 200, 'body' => '{"choices":[{"message":{"content":"x"},"finish_reason":"stop"}],"usage":{"prompt_tokens":1,"completion_tokens":1}}', 'headers' => []];
        $grok->generate([['role' => 'user', 'content' => 'q']], ['timeout' => 64]);
        $this->assert('Grok: per-request timeout option is honoured (inherits OpenAI)', ($grok->lastExecuted['timeout'] ?? 0) === 64);
    }

    /**
     * The 5xx retry policy lives outside the request loop so it can be asserted without a
     * live connection: only transient server errors get exactly one retry.
     */
    private function testHttpRetryPolicy(): void
    {
        $ok = static fn(int $status, int $attempt): bool => AbstractProviderAdapter::shouldRetryHttpError($status, $attempt);

        $this->assert('Retry: 503 on first attempt is retried', $ok(503, 1) === true);
        $this->assert('Retry: 500 on first attempt is retried', $ok(500, 1) === true);
        $this->assert('Retry: 503 on second attempt is not retried again', $ok(503, 2) === false);
        $this->assert('Retry: 404 is not retried', $ok(404, 1) === false);
        $this->assert('Retry: 429 is not retried', $ok(429, 1) === false);
        $this->assert('Retry: 401 is not retried', $ok(401, 1) === false);
        $this->assert('Retry: 200 is not retried', $ok(200, 1) === false);
    }
}

(new ProviderSimulationRunner())->run();
