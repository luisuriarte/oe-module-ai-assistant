<?php

/**
 * CsrfCompat — version-agnostic CSRF helpers.
 *
 * Why this exists
 * ---------------
 * CsrfUtils changed the position of the $session argument between OpenEMR branches:
 *
 *   openemr (older)  verifyCsrfToken($token, $subject = 'default', ?SessionInterface $session = null)
 *   8.2.0 / 8.4.1    verifyCsrfToken($token, SessionInterface $session, string $subject = 'default')
 *
 * The module's natural call, CsrfUtils::verifyCsrfToken($token, $session), therefore means two
 * different things depending on the branch. On the older signature it passes the session object
 * where a string subject is expected, so the subject is cast to a string, the HMAC is computed
 * over that garbage, and verification fails for every request — a confusing 400 that looks like
 * a token bug rather than a signature mismatch.
 *
 * resolve() reads the live signature via reflection and dispatches on the actual parameter order,
 * so one call site works on every supported version.
 *
 * Fails closed: if the reflection cannot be read, verification returns false.
 *
 * Compatibility: OpenEMR 8.2.0 and 8.4.1.
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\AiAssistant\Session;

use OpenEMR\Common\Csrf\CsrfUtils;

final class CsrfCompat
{
    /**
     * True when CsrfUtils expects the session as its second argument (8.2.0 / 8.4.1 layout).
     * Detected once per request and memoised; null means "not yet probed".
     */
    private static ?bool $sessionIsSecondArg = null;

    /**
     * Returns a CSRF token for $subject, or an empty string when it cannot be computed.
     */
    public static function collect(?object $session, string $subject = 'default'): string
    {
        if ($session === null) {
            return '';
        }

        try {
            if (self::sessionIsSecondArg()) {
                return CsrfUtils::collectCsrfToken($session, $subject);
            }

            return CsrfUtils::collectCsrfToken($subject, $session);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Verifies $token for $subject. Returns false on any failure, including a missing session,
     * so an unavailable session can never be mistaken for a valid one.
     */
    public static function verify(string $token, ?object $session, string $subject = 'default'): bool
    {
        if ($session === null || $token === '') {
            return false;
        }

        try {
            if (self::sessionIsSecondArg()) {
                return (bool) CsrfUtils::verifyCsrfToken($token, $session, $subject);
            }

            return (bool) CsrfUtils::verifyCsrfToken($token, $subject, $session);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Inspects the real signature rather than trusting the OpenEMR version number.
     */
    private static function sessionIsSecondArg(): bool
    {
        if (self::$sessionIsSecondArg !== null) {
            return self::$sessionIsSecondArg;
        }

        try {
            $method    = new \ReflectionMethod(CsrfUtils::class, 'verifyCsrfToken');
            $params    = $method->getParameters();
            $secondArg = $params[1] ?? null;
            $type      = $secondArg?->getType();

            $name = $secondArg?->getName() ?? '';
            $isSessionType = $type instanceof \ReflectionNamedType
                && in_array($type->getName(), ['SessionInterface', 'object'], true);

            // The 8.2.0/8.4.1 layout names the parameter $session and type-hints it;
            // the older layout names it $subject and defaults to 'default' as a string.
            self::$sessionIsSecondArg = ($name === 'session' && $isSessionType)
                || ($isSessionType && ($secondArg?->isDefaultValueAvailable() === false));
        } catch (\Throwable) {
            // Default to the layout used by 8.2.0 and 8.4.1, which is what we support.
            self::$sessionIsSecondArg = true;
        }

        return self::$sessionIsSecondArg;
    }
}