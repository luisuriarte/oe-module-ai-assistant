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
    protected string $providerName;

    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, string $providerName = '')
    {
        parent::__construct($message, $code, $previous);
        $this->providerName = $providerName;
    }

    public function getProviderName(): string
    {
        return $this->providerName;
    }
}
