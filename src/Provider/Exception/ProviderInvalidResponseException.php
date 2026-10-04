<?php

/**
 * ProviderInvalidResponseException — Thrown when the provider returns malformed JSON or an unexpected structure.
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\AiAssistant\Provider\Exception;

class ProviderInvalidResponseException extends ProviderException
{
    private int $httpStatusCode;

    public function __construct(string $message = '', int $httpStatusCode = 0, ?\Throwable $previous = null, string $providerName = '')
    {
        parent::__construct($message, $httpStatusCode, $previous, $providerName);
        $this->httpStatusCode = $httpStatusCode;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }
}
