<?php

/**
 * ProviderSafetyBlockException — Thrown when content is blocked by the provider's safety or content moderation filter.
 *
 * Specific to Gemini finishReason === 'SAFETY' / promptFeedback blockReason, or empty candidate filters.
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\AiAssistant\Provider\Exception;

class ProviderSafetyBlockException extends ProviderException
{
    private string $blockReason;

    public function __construct(string $message = '', string $blockReason = '', ?\Throwable $previous = null, string $providerName = '')
    {
        parent::__construct($message, 0, $previous, $providerName);
        $this->blockReason = $blockReason;
    }

    public function getBlockReason(): string
    {
        return $this->blockReason;
    }
}
