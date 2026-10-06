<?php

/**
 * ProviderException — Base exception for all AI provider communication failures.
 *
 * All subclasses represent uniform error categories across OpenAI, Anthropic, and Gemini.
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\AiAssistant\Provider\Exception;

use RuntimeException;
use Throwable;

class ProviderException extends RuntimeException
{
    /**
     * Fixed, machine-readable error codes. These are stable identifiers used for audit,
     * logs and API responses; they never vary with the provider's own wording.
     */
    public const CODE_AUTH_FAILED   = 'PROVIDER_AUTH_FAILED';
    public const CODE_RATE_LIMIT    = 'PROVIDER_RATE_LIMITED';
    public const CODE_TIMEOUT       = 'PROVIDER_TIMEOUT';
    public const CODE_SAFETY_BLOCK  = 'PROVIDER_SAFETY_BLOCK';
    public const CODE_HTTP_ERROR    = 'PROVIDER_HTTP_ERROR';
    public const CODE_BAD_RESPONSE  = 'PROVIDER_BAD_RESPONSE';
    public const CODE_NETWORK_ERROR = 'PROVIDER_NETWORK_ERROR';
    public const CODE_UNKNOWN       = 'PROVIDER_UNKNOWN';

    protected string $providerName;

    /**
     * Sanitized provider detail: HTTP status plus the provider's error type/code only.
     *
     * The provider's message body is deliberately never stored here — it can echo the
     * prompt back, and prompt content must not reach logs or API responses.
     */
    protected string $errorDetail = '';

    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, string $providerName = '')
    {
        parent::__construct($message, $code, $previous);
        $this->providerName = $providerName;
    }

    public function getProviderName(): string
    {
        return $this->providerName;
    }

    /**
     * One of the fixed CODE_* identifiers above.
     */
    public function fixedCode(): string
    {
        return match (true) {
            $this instanceof ProviderAuthenticationException => self::CODE_AUTH_FAILED,
            $this instanceof ProviderRateLimitException      => self::CODE_RATE_LIMIT,
            $this instanceof ProviderTimeoutException        => self::CODE_TIMEOUT,
            $this instanceof ProviderSafetyBlockException    => self::CODE_SAFETY_BLOCK,
            $this->getCode() !== 0                          => self::CODE_HTTP_ERROR,
            default                                          => self::CODE_BAD_RESPONSE,
        };
    }

    /**
     * Sanitized detail (HTTP status + provider error type/code), never the body.
     */
    public function setErrorDetail(string $detail): static
    {
        // Strip anything that could carry prompt text: keep only a short, typed fragment.
        $this->errorDetail = mb_strimwidth(
            preg_replace('/[^\w\s|:.=-]/', '', $detail) ?? '',
            0,
            200
        );

        return $this;
    }

    public function errorDetail(): string
    {
        return $this->errorDetail;
    }
}
