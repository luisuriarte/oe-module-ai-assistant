<?php

/**
 * ProviderErrorResponder - shared error mapping for AI endpoints.
 *
 * Extracted so DraftController (Layer 1) and ChatController (Layer 2) report provider
 * failures identically. The two rules that matter:
 *
 *   1. The provider's own words are withheld unless debug_log_content is enabled. They
 *      are free-form, untranslated, and can echo prompt content back into the log or
 *      the HTTP response. The fixed error code is always returned separately so the
 *      failure stays identifiable.
 *   2. Only fixed codes ever reach audit rows or logs - never the exception message and
 *      never the provider response body.
 *
 * Requires the using class to declare `private SettingsManager $settings`.
 *
 * Compatibility: OpenEMR 8.2.0+ (PHP 8.2 compatible).
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Controller;

use OpenEMR\Modules\AiAssistant\Provider\Exception\ProviderAuthenticationException;
use OpenEMR\Modules\AiAssistant\Provider\Exception\ProviderException;
use OpenEMR\Modules\AiAssistant\Provider\Exception\ProviderInvalidResponseException;
use OpenEMR\Modules\AiAssistant\Provider\Exception\ProviderRateLimitException;
use OpenEMR\Modules\AiAssistant\Provider\Exception\ProviderSafetyBlockException;
use OpenEMR\Modules\AiAssistant\Provider\Exception\ProviderTimeoutException;

trait ProviderErrorResponder
{
    /**
     * Returns the fixed error code for an exception, for audit and API responses.
     */
    private function fixedErrorCode(\Throwable $e): string
    {
        if ($e instanceof ProviderException) {
            return $e->fixedCode();
        }

        return match (true) {
            $e instanceof \InvalidArgumentException => 'REQUEST_INVALID',
            $e instanceof \RuntimeException         => 'REQUEST_FAILED',
            default                                 => 'UNEXPECTED',
        };
    }

    /**
     * Maps exceptions to a localized, clinician-facing message.
     *
     * @param string $genericMessage Already-translated fallback for unexpected failures
     */
    private function mapExceptionToMessage(\Throwable $e, string $genericMessage): string
    {
        $message = match (true) {
            $e instanceof ProviderAuthenticationException =>
                xlt('AI provider authentication failed. Please verify your API key in AI Assistant Settings.'),
            $e instanceof ProviderRateLimitException =>
                xlt('AI provider rate limit reached. Please wait a few seconds before retrying.'),
            $e instanceof ProviderSafetyBlockException =>
                xlt('The consultation content was flagged by the AI provider safety filter.'),
            $e instanceof ProviderTimeoutException =>
                xlt('The AI provider request timed out. Please try again or check your network connectivity.'),
            $e instanceof ProviderInvalidResponseException =>
                xlt('The AI provider rejected the request.'),
            $e instanceof ProviderException =>
                xlt('AI provider communication failed. Please retry.'),
            default =>
                $e instanceof \InvalidArgumentException
                    ? $e->getMessage()
                    : $genericMessage,
        };

        if ($this->debugEnabled() && trim($e->getMessage()) !== '') {
            $message .= ' [' . $e->getMessage() . ']';
        }

        return $message;
    }

    /**
     * True only when an admin explicitly enabled verbose debug logging.
     */
    private function debugEnabled(): bool
    {
        try {
            return (string) $this->settings->get('debug_log_content', '0') === '1';
        } catch (\Throwable) {
            return false;
        }
    }
}
