<?php

/**
 * ProviderResponse — DTO representing the response from an AI provider.
 *
 * Contains generated text, token usage metadata, model name, and raw response.
 *
 * Compatibility: OpenEMR 8.2.0+ (PHP 8.2 compatible).
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\AiAssistant\Provider;

class ProviderResponse
{
    public function __construct(
        public readonly string $text = '',
        public readonly int $tokensIn = 0,
        public readonly int $tokensOut = 0,
        public readonly string $model = '',
        public readonly array $raw = []
    ) {}

    public function getTotalTokens(): int
    {
        return $this->tokensIn + $this->tokensOut;
    }
}
