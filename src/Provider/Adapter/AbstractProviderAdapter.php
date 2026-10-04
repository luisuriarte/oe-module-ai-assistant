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
     */
    public static function isPrivateOrReservedHost(string $host): bool
    {
        if (in_array(strtolower($host), ['localhost', 'metadata.google.internal', '169.254.169.254'], true)) {
            return true;
        }

        // Check if host is direct IP
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPrivateIp($host);
        }

        // Resolve DNS and check resulting IP
        $ip = gethostbyname($host);
        if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) {
            return self::isPrivateIp($ip);
        }

        return false;
    }

    private static function isPrivateIp(string $ip): bool
    {
        // FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE returns false if IP is private/reserved
        $filtered = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        return ($filtered === false);
    }

    /**
     * Executes a cURL request with strict security settings.
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
        $ch = curl_init();
        $timeout = $customTimeout ?? $this->timeoutSec;

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

        $providerName = $this->getProviderName();

        // 1. Connection / Timeout errors
        if ($curlError !== '') {
            if ($curlErrno === CURLE_OPERATION_TIMEDOUT) {
                throw new ProviderTimeoutException(
                    "Request to {$providerName} timed out after {$timeout} seconds.",
                    $curlErrno,
                    null,
                    $providerName
                );
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
            throw new ProviderAuthenticationException(
                "Authentication failed with {$providerName} (HTTP {$statusCode}). Check your API key.",
                $statusCode,
                null,
                $providerName
            );
        }

        // 3. Rate limiting (429)
        if ($statusCode === 429) {
            $retryAfter = null;
            if (!empty($responseHeaders['retry-after'])) {
                $retryAfter = (int) $responseHeaders['retry-after'];
            }
            throw new ProviderRateLimitException(
                "{$providerName} rate limit or quota exceeded (HTTP 429).",
                $statusCode,
                null,
                $providerName,
                $retryAfter
            );
        }

        // 4. Server errors (5xx) or unexpected status
        if ($statusCode >= 500) {
            throw new ProviderInvalidResponseException(
                "{$providerName} service unavailable or internal error (HTTP {$statusCode}).",
                $statusCode,
                null,
                $providerName
            );
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new ProviderInvalidResponseException(
                "{$providerName} returned unexpected status (HTTP {$statusCode}).",
                $statusCode,
                null,
                $providerName
            );
        }

        return [
            'statusCode' => $statusCode,
            'body'       => (string) $body,
            'headers'    => $responseHeaders,
        ];
    }
}
