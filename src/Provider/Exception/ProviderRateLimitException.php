<?php

/**
 * ProviderRateLimitException — Thrown when the provider returns HTTP 429 (rate limit or quota exceeded).
 *
 * Direct failure without infinite or blocking retry loops.
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\AiAssistant\Provider\Exception;

class ProviderRateLimitException extends ProviderException
{
    private ?int $retryAfterSec;

    public function __construct(string $message = '', int $code = 429, ?\Throwable $previous = null, string $providerName = '', ?int $retryAfterSec = null)
    {
        parent::__construct($message, $code, $previous, $providerName);
        $this->retryAfterSec = $retryAfterSec;
    }

    public function getRetryAfterSec(): ?int
    {
        return $this->retryAfterSec;
    }
}
