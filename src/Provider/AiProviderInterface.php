<?php
namespace OpenEMR\Modules\AiAssistant\Provider;
/** Placeholder — implemented in M3. */
interface AiProviderInterface {
    public function generate(array $messages, array $options = []): ProviderResponse;
}
