<?php

/**
 * AbstractProviderAdapter — Base class for AI provider HTTP adapters.
 *
 * Implements common network security controls:
 *   - Base URL validation (HTTPS only, no embedded credentials, SSRF protection against private IPs).
 *   - TLS verification (peer and host).
 *   - Strict connect and read timeouts.
 *   - Blind redirect suppression (FOLLOWLOCATION = false).
 *   - Zero-logging of prompts, responses, or error bodies.
 *   - Standardized HTTP status mapping to ProviderException hierarchy.
 *
 * Compatibility: OpenEMR 8.2.0+ (PHP 8.2 compatible).
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\AiAssistant\Provider\Adapter;

use InvalidArgumentException;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Modules\AiAssistant\Provider\AiProviderInterface;
use OpenEMR\Modules\AiAssistant\Provider\Exception\ProviderAuthenticationException;
use OpenEMR\Modules\AiAssistant\Provider\Exception\ProviderException;
use OpenEMR\Modules\AiAssistant\Provider\Exception\ProviderInvalidResponseException;
use OpenEMR\Modules\AiAssistant\Provider\Exception\ProviderRateLimitException;
use OpenEMR\Modules\AiAssistant\Provider\Exception\ProviderTimeoutException;

abstract class AbstractProviderAdapter implements AiProviderInterface
{
    protected string $apiKey;
    protected string $model;
    protected float $temperature;
    protected int $maxTokens;
    protected int $timeoutSec;
    protected ?SystemLogger $logger = null;

    /**
     * Total attempts for an individual request. Only transient 5xx responses are retried
     * (see shouldRetryHttpError); 4xx, 429, 401/403 and transport failures never are.
     */
    protected const MAX_HTTP_RETRY_ATTEMPTS = 2;

    public function __construct(
        string $apiKey,
        string $model,
        float $temperature = 0.2,
        int $maxTokens = 2048,
        int $timeoutSec = 30
    ) {
        $this->apiKey      = trim($apiKey);
        $this->model       = trim($model);
        $this->temperature = max(0.0, min(2.0, $temperature));
        $this->maxTokens   = max(1, $maxTokens);
        $this->timeoutSec  = max(5, $timeoutSec);
        if (class_exists(SystemLogger::class)) {
            $this->logger = new SystemLogger();
        }
    }

    /**
     * Validates an HTTPS endpoint URL and guards against SSRF / private IP access.
     *
     * @param string $url
     * @param bool   $allowPrivate Whether to allow localhost/private networks (e.g. for self-hosted OpenAI proxy)
     * @return string Normalized URL without trailing slash
     * @throws InvalidArgumentException
     */
    public static function validateBaseUrl(string $url, bool $allowPrivate = false): string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Provider base URL cannot be empty.');
        }

        $parts = parse_url($trimmed);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            throw new InvalidArgumentException('Invalid provider URL format.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'https') {
            // Only permit HTTP if private hosts are explicitly enabled (e.g. local test proxy)
            if (!$allowPrivate || $scheme !== 'http') {
                throw new InvalidArgumentException('Provider base URL must use HTTPS.');
            }
        }

        if (!empty($parts['user']) || !empty($parts['pass'])) {
            throw new InvalidArgumentException('Embedded credentials in provider URL are strictly forbidden.');
        }

        $host = (string) $parts['host'];

        // SSRF protection: block private, link-local, loopback, and cloud metadata addresses
        if (!$allowPrivate && self::isPrivateOrReservedHost($host)) {
            throw new InvalidArgumentException("Connecting to private, local, or reserved host '{$host}' is blocked for security.");
        }

        $port = !empty($parts['port']) ? ':' . $parts['port'] : '';
        $path = !empty($parts['path']) ? rtrim($parts['path'], '/') : '';

        return $scheme . '://' . $host . $port . $path;
    }

    /**
     * Checks if a host resolves to a private, loopback, or link-local IP range.
     * Supports both IPv4 and IPv6 resolution and literal formats.
     */
    public static function isPrivateOrReservedHost(string $host): bool
    {
        $cleanHost = strtolower(trim($host, '[]'));

        if (in_array($cleanHost, ['localhost', 'metadata.google.internal', '169.254.169.254', '::1', '0.0.0.0'], true)) {
            return true;
        }

        // Check if host is direct IP literal (IPv4 or IPv6)
        if (filter_var($cleanHost, FILTER_VALIDATE_IP)) {
            return self::isPrivateIp($cleanHost);
        }

        // 1. Resolve DNS records for both IPv4 and IPv6
        $ips = [];
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($cleanHost, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $rec) {
                    if (isset($rec['ip'])) {
                        $ips[] = (string) $rec['ip'];
                    } elseif (isset($rec['ipv6'])) {
                        $ips[] = (string) $rec['ipv6'];
                    }
                }
            }
        }

        // Fallback to gethostbynamel
        if (empty($ips) && function_exists('gethostbynamel')) {
            $ipv4s = @gethostbynamel($cleanHost);
            if (is_array($ipv4s)) {
                $ips = array_merge($ips, $ipv4s);
            }
        }

        if (empty($ips)) {
            $single = @gethostbyname($cleanHost);
            if ($single !== $cleanHost && filter_var($single, FILTER_VALIDATE_IP)) {
                $ips[] = $single;
            }
        }

        foreach ($ips as $ip) {
            if (self::isPrivateIp($ip)) {
                return true;
            }
        }

        return false;
    }

    public static function isPrivateIp(string $ip): bool
    {
        // 1. Standard PHP filter check for private and reserved ranges
        $filtered = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($filtered === false) {
            return true;
        }

        // 2. Extra IPv6 checks for ULA (fc00::/7), link-local (fe80::/10), and IPv4-mapped (::ffff:127.0.0.1)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $lower = strtolower($ip);
            if (str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd')) {
                return true; // Unique Local Address (ULA)
            }
            if (str_starts_with($lower, 'fe8') || str_starts_with($lower, 'fe9') || str_starts_with($lower, 'fea') || str_starts_with($lower, 'feb')) {
                return true; // Link-local
            }
            if (str_starts_with($lower, '::ffff:')) {
                $ipv4Part = substr($lower, 7);
                if (filter_var($ipv4Part, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return self::isPrivateIp($ipv4Part);
                }
            }
        }

        return false;
    }

    /**
     * Executes a cURL request with strict security settings.
     *
     * Transient 5xx responses (provider overload, internal errors) are retried once with a
     * short back-off. That is safe here: every call races a fresh generation (no side
     * effects), and a 5xx fails fast in milliseconds — never after a long wait — so the
     * retry adds little wall time and stays inside the request/Cloudflare budget.
     *
     * Nothing else is retried: authentication (401/403), rate limits (429) and transport
     * failures are surfaced on the first attempt, and other 4xx responses are also
     * surfaced immediately — retrying them would just repeat the same parameter error.
     *
     * @param string                $url
     * @param string                $method 'GET' or 'POST'
     * @param array<string, string> $headers
     * @param string|null           $jsonBody
     * @param int|null              $customTimeout
     * @return array{statusCode: int, body: string, headers: array<string, string>}
     * @throws ProviderException
     */
    protected function executeRequest(
        string $url,
        string $method = 'POST',
        array $headers = [],
        ?string $jsonBody = null,
        ?int $customTimeout = null
    ): array {
        $timeout = $customTimeout ?? $this->timeoutSec;
        $providerName = $this->getProviderName();

        for ($attempt = 1; $attempt <= self::MAX_HTTP_RETRY_ATTEMPTS; $attempt++) {
            $ch = curl_init();

            $responseHeaders = [];
            $headerCallback = function ($curl, string $headerLine) use (&$responseHeaders) {
                $len = strlen($headerLine);
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            };

            $curlHeaders = [];
            foreach ($headers as $k => $v) {
                $curlHeaders[] = "{$k}: {$v}";
            }

            $opts = [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADERFUNCTION => $headerCallback,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false, // No blind redirects
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_HTTPHEADER     => $curlHeaders,
                CURLOPT_USERAGENT      => 'OpenEMR-AiAssistant-Provider/1.0',
            ];

            if ($method === 'POST') {
                $opts[CURLOPT_POST] = true;
                if ($jsonBody !== null) {
                    $opts[CURLOPT_POSTFIELDS] = $jsonBody;
                }
            } elseif ($method === 'GET') {
                $opts[CURLOPT_HTTPGET] = true;
            }

            curl_setopt_array($ch, $opts);

            $body       = curl_exec($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError  = curl_error($ch);
            $curlErrno  = curl_errno($ch);
            curl_close($ch);

            // 1. Connection / Timeout errors
            if ($curlError !== '') {
                if ($curlErrno === CURLE_OPERATION_TIMEDOUT) {
                    throw (new ProviderTimeoutException(
                        "Request to {$providerName} timed out after {$timeout} seconds.",
                        $curlErrno,
                        null,
                        $providerName
                    ))->setErrorDetail("timeout_sec={$timeout}");
                }
                throw new ProviderException(
                    "Network error connecting to {$providerName}: {$curlError}",
                    $curlErrno,
                    null,
                    $providerName
                );
            }

            // 2. Authentication errors (401, 403)
            if ($statusCode === 401 || $statusCode === 403) {
                throw (new ProviderAuthenticationException(
                    "Authentication failed with {$providerName} (HTTP {$statusCode}). Check your API key.",
                    $statusCode,
                    null,
                    $providerName
                ))->setErrorDetail("http_status={$statusCode}");
            }

            // 3. Rate limiting (429)
            if ($statusCode === 429) {
                $retryAfter = null;
                if (!empty($responseHeaders['retry-after'])) {
                    $retryAfter = (int) $responseHeaders['retry-after'];
                }
                throw (new ProviderRateLimitException(
                    "{$providerName} rate limit or quota exceeded (HTTP 429).",
                    $statusCode,
                    null,
                    $providerName,
                    $retryAfter
                ))->setErrorDetail("http_status=429");
            }

            // 4. Server errors (5xx): transient provider outage — one retry, then throw.
            if ($statusCode >= 500) {
                if (self::shouldRetryHttpError($statusCode, $attempt)) {
                    usleep(1000000); // 1 s back-off; 5xx fail fast, so this stays inside the budget
                    continue;
                }
                throw (new ProviderInvalidResponseException(
                    sprintf('%s service unavailable or internal error (HTTP %d)', $providerName, $statusCode),
                    $statusCode,
                    null,
                    $providerName
                ))->setErrorDetail(self::providerErrorDetail((string) $body, $statusCode));
            }

            if ($statusCode < 200 || $statusCode >= 300) {
                // A 4xx carries the real reason (model not found, bad parameter, unsupported
                // endpoint) as an error type/code. Only that typed fragment is kept: the
                // provider's message body is never retained because it can echo the prompt.
                throw (new ProviderInvalidResponseException(
                    sprintf('%s rejected the request (HTTP %d)', $providerName, $statusCode),
                    $statusCode,
                    null,
                    $providerName
                ))->setErrorDetail(self::providerErrorDetail($body, $statusCode));
            }

            return [
                'statusCode' => $statusCode,
                'body'       => (string) $body,
                'headers'    => $responseHeaders,
            ];
        }

        // Unreachable: the loop either returns or throws on every path.
        throw new ProviderException("Unexpected request failure to {$providerName}.");
    }

    /**
     * Retry policy for executeRequest.
     *
     * Arbitrates attempts: transient 5xx responses get exactly one retry; everything else
     * (4xx, 429, 401/403) returns on first sight. Holed out of the request loop so the
     * policy stays unit-testable without a live connection.
     */
    public static function shouldRetryHttpError(int $statusCode, int $attempt): bool
    {
        return $statusCode >= 500 && $attempt < 2;
    }

    /**
     * Extracts only the HTTP status and the provider's error type/code.
     *
     * Shape: {"error": {"type": "...", "code": "..."}} — the `message` field is ignored on
     * purpose. Anything that is not that shape collapses to a fixed token, so no fragment
     * of an error body can ever reach a log line or an API response.
     */
    private static function providerErrorDetail(string $body, int $statusCode): string
    {
        $detail = 'http_status=' . $statusCode;

        $decoded = json_decode((string) $body, true);
        if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])) {
            $type = trim((string) ($decoded['error']['type'] ?? ''));
            $code = trim((string) ($decoded['error']['code'] ?? ''));
            if ($type !== '') {
                $detail .= ' type=' . preg_replace('/[^\w.\-]/', '', $type);
            }
            if ($code !== '') {
                $detail .= ' code=' . preg_replace('/[^\w.\-]/', '', $code);
            }

            return $detail;
        }

        return $detail . ' body=' . (is_array($decoded) ? 'json_object' : 'non_json');
    }
}
