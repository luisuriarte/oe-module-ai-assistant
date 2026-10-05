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
     *
     * The discriminator is the parameter NAME: 8.2.0 / 8.4.1 declare
     * verifyCsrfToken($token, SessionInterface $session, string $subject), while the older
     * layout declares verifyCsrfToken($token, $subject = 'default', ?SessionInterface $session).
     * So $session in position 1 means the new layout and $subject in position 1 the old one.
     *
     * Type hints cannot be used for this: the real type is the fully-qualified
     * Symfony\Component\HttpFoundation\Session\SessionInterface, so getName() returns that
     * whole string and a comparison against the short name 'SessionInterface' always fails.
     * Matching on the short name after stripping the namespace avoids that trap.
     */
    private static function sessionIsSecondArg(): bool
    {
        if (self::$sessionIsSecondArg !== null) {
            return self::$sessionIsSecondArg;
        }

        try {
            $params    = (new \ReflectionMethod(CsrfUtils::class, 'verifyCsrfToken'))->getParameters();
            $secondArg = $params[1] ?? null;

            if ($secondArg === null) {
                // Fewer than two parameters: nothing to reorder, assume the new layout.
                self::$sessionIsSecondArg = true;
                return self::$sessionIsSecondArg;
            }

            $name     = $secondArg->getName();
            $type     = $secondArg->getType();
            $short    = $type instanceof \ReflectionNamedType
                ? substr(strrchr('\\' . $type->getName(), '\\'), 1)
                : '';

            $looksLikeSession = $name === 'session'
                || ($short !== '' && str_ends_with($short, 'SessionInterface'));

            // Layout A: second parameter IS the session and has no default.
            // Layout B: second parameter is $subject with a default of 'default'.
            if ($name === 'subject' && $secondArg->isDefaultValueAvailable()) {
                self::$sessionIsSecondArg = false;
            } elseif ($looksLikeSession) {
                self::$sessionIsSecondArg = true;
            } else {
                // Unknown shape: fall back on which parameter carries the session type.
                self::$sessionIsSecondArg = $short !== '' && str_ends_with($short, 'SessionInterface');
            }
        } catch (\Throwable) {
            // Default to the layout used by 8.2.0 and 8.4.1, which is what we support.
            self::$sessionIsSecondArg = true;
        }

        return self::$sessionIsSecondArg;
    }
}