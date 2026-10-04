<?php
namespace OpenEMR\Modules\AiAssistant\Provider;
/** Placeholder — implemented in M3. */
class ProviderResponse {
    public function __construct(
        public readonly string $text = '',
        public readonly int $tokensIn = 0,
        public readonly int $tokensOut = 0,
        public readonly array $raw = []
    ) {}
}
