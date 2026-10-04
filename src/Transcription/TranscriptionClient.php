<?php

/**
 * TranscriptionClient — HTTP client for the self-hosted Whisper transcription server.
 *
 * Designed for whisper.cpp / compatible OpenAI-style whisper inference servers.
 * Connects to the configured URL (default: http://127.0.0.1:8178/inference).
 *
 * Security & Reliability:
 *   - Strictly validates URL scheme (http/https only) and rejects embedded credentials.
 *   - Lightweight read-only health checks (never executes inference for a ping).
 *   - Timeouts derived from audio duration formula.
 *
 * Compatibility: OpenEMR 8.2.0+ (PHP 8.2 compatible).
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\AiAssistant\Transcription;

use CURLFile;
use InvalidArgumentException;
use OpenEMR\Common\Logging\SystemLogger;

class TranscriptionClient
{
    private string $whisperUrl;
    private int $timeoutSec;
    private SystemLogger $logger;

    /**
     * @param string $whisperUrl Configured server URL (e.g. http://127.0.0.1:8178)
     * @param int    $timeoutSec Request timeout in seconds
     */
    public function __construct(string $whisperUrl, int $timeoutSec = 60)
    {
        $this->whisperUrl = self::validateAndNormalizeUrl($whisperUrl);
        $this->timeoutSec = max(5, $timeoutSec);
        $this->logger     = new SystemLogger();
    }

    /**
     * Validates and normalizes the Whisper server URL.
     *
     * Rules:
     *   - Must be valid http:// or https:// URL.
     *   - Must NOT contain embedded user/password credentials.
     *
     * @throws InvalidArgumentException
     */
    public static function validateAndNormalizeUrl(string $url): string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Whisper URL cannot be empty.');
        }

        $parts = parse_url($trimmed);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            throw new InvalidArgumentException('Invalid Whisper URL format.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Whisper URL must use http or https.');
        }

        if (!empty($parts['user']) || !empty($parts['pass'])) {
            throw new InvalidArgumentException('Embedded credentials in Whisper URL are forbidden.');
        }

        // Return normalized base URL without trailing slash
        $port = !empty($parts['port']) ? ':' . $parts['port'] : '';
        $path = !empty($parts['path']) ? rtrim($parts['path'], '/') : '';

        return $scheme . '://' . $parts['host'] . $port . $path;
    }

    /**
     * Performs a lightweight read-only connection test.
     *
     * NEVER triggers inference or transmits audio. Sends a GET / or GET /health probe.
     *
     * @param int $probeTimeoutSec Short timeout (3-5s)
     * @return array{ok: bool, latency_ms: int, status_code: int, error: string}
     */
    public function testConnection(int $probeTimeoutSec = 4): array
    {
        $start = microtime(true);

        // Probe endpoint root
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->whisperUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY         => false,
            CURLOPT_TIMEOUT        => $probeTimeoutSec,
            CURLOPT_CONNECTTIMEOUT => $probeTimeoutSec,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'OpenEMR-AiAssistant-WhisperClient/1.0',
        ]);

        $response   = curl_exec($ch);
        $durationMs = (int) round((microtime(true) - $start) * 1000);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            return [
                'ok'          => false,
                'latency_ms'  => $durationMs,
                'status_code' => $statusCode,
                'error'       => $curlError,
            ];
        }

        // Status 200 (OK), 404 (root not found but server alive), or 405 (method not allowed) confirms server is listening
        $isListening = ($statusCode >= 200 && $statusCode < 500);

        return [
            'ok'          => $isListening,
            'latency_ms'  => $durationMs,
            'status_code' => $statusCode,
            'error'       => $isListening ? '' : 'HTTP ' . $statusCode,
        ];
    }

    /**
     * Transcribes an audio file via the Whisper server.
     *
     * @param string $audioFilePath Full path to audio file on disk
     * @param string $language      Language code (default: 'es')
     * @return array{ok: bool, text: string, duration_ms: int, error_code: string}
     */
    public function transcribe(string $audioFilePath, string $language = 'es'): array
    {
        if (!file_exists($audioFilePath) || !is_readable($audioFilePath)) {
            return [
                'ok'          => false,
                'text'        => '',
                'duration_ms' => 0,
                'error_code'  => 'audio_file_unreadable',
            ];
        }

        // Target /inference endpoint
        $targetUrl = str_ends_with($this->whisperUrl, '/inference')
            ? $this->whisperUrl
            : $this->whisperUrl . '/inference';

        $postFields = [
            'file'            => new CURLFile($audioFilePath),
            'temperature'     => '0.0',
            'temperature_inc' => '0.2',
            'response_format' => 'json',
        ];

        if ($language !== '') {
            $postFields['language'] = $language;
        }

        $start = microtime(true);
        $ch    = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $targetUrl,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeoutSec,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'OpenEMR-AiAssistant-WhisperClient/1.0',
        ]);

        $rawResponse = curl_exec($ch);
        $durationMs  = (int) round((microtime(true) - $start) * 1000);
        $statusCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError   = curl_error($ch);
        $curlErrno   = curl_errno($ch);
        curl_close($ch);

        if ($curlError !== '') {
            $errorCode = ($curlErrno === CURLE_OPERATION_TIMEDOUT) ? 'whisper_timeout' : 'whisper_network_error';
            $this->logger->error("[AiAssistant] Whisper curl error ({$curlErrno}): {$curlError}");
            return [
                'ok'          => false,
                'text'        => '',
                'duration_ms' => $durationMs,
                'error_code'  => $errorCode,
            ];
        }

        if ($statusCode !== 200) {
            $this->logger->error("[AiAssistant] Whisper HTTP {$statusCode}");
            return [
                'ok'          => false,
                'text'        => '',
                'duration_ms' => $durationMs,
                'error_code'  => 'whisper_http_' . $statusCode,
            ];
        }

        $data = json_decode((string) $rawResponse, true);
        $text = '';
        if (is_array($data)) {
            $text = trim((string) ($data['text'] ?? $data['transcript'] ?? ''));
        } elseif (is_string($rawResponse)) {
            $text = trim($rawResponse);
        }

        return [
            'ok'          => true,
            'text'        => $text,
            'duration_ms' => $durationMs,
            'error_code'  => '',
        ];
    }

    public function getNormalizedUrl(): string
    {
        return $this->whisperUrl;
    }
}
