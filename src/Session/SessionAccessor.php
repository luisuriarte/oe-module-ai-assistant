<?php

/**
 * SessionAccessor — version-agnostic access to the OpenEMR session.
 *
 * Why this exists
 * ---------------
 * The supported OpenEMR branches expose two different session APIs:
 *
 *   OpenEMR 8.2.0 / 8.4.1  SessionWrapperFactory::getActiveSession() exists
 *   older trees             SessionWrapperFactory::getWrapper()       exists
 *
 * Both are instance methods reached via getInstance() (the factory uses SingletonTrait);
 * neither may be called statically.
 *
 * resolve() probes whichever accessor the running version provides. If neither exists the
 * caller receives null and must handle it — no session means no authenticated request, so
 * every caller fails closed rather than assuming an empty session is authenticated.
 *
 * currentUserId() derives the authenticated user id from that session and returns null
 * when it cannot be determined. Callers must treat null as unauthenticated (HTTP 401);
 * there is deliberately no default user id anywhere in this module.
 *
 * Compatibility: OpenEMR 8.2.0 and 8.4.1, plus older trees exposing getWrapper().
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\AiAssistant\Session;

use OpenEMR\Common\Session\SessionWrapperFactory;

final class SessionAccessor
{
    /**
     * Returns a session object exposing get()/set(), or null when unavailable.
     *
     * Return type is intentionally loose: 8.2.0 returns SessionWrapperInterface while
     * 8.4.1 returns Symfony's SessionInterface. Both satisfy the get()/set() contract
     * this module relies on, and naming either one would break the other branch.
     */
    public static function resolve(): ?object
    {
        try {
            $factory = SessionWrapperFactory::getInstance();
        } catch (\Throwable) {
            return null;
        }

        if (!is_object($factory)) {
            return null;
        }

        if (method_exists($factory, 'getActiveSession')) {
            try {
                $session = $factory->getActiveSession();
                return is_object($session) ? $session : null;
            } catch (\Throwable) {
                return null;
            }
        }

        if (method_exists($factory, 'getWrapper')) {
            try {
                $session = $factory->getWrapper();
                return is_object($session) ? $session : null;
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Returns the authenticated user id, or null when it cannot be established.
     *
     * There is intentionally no fallback: a request without an authenticated user is
     * rejected with 401 rather than attributed to an arbitrary account.
     */
    public static function currentUserId(): ?int
    {
        $session = self::resolve();
        if ($session === null) {
            return null;
        }

        foreach (['authUserID', 'authId', 'id'] as $key) {
            try {
                $value = $session->get($key);
            } catch (\Throwable) {
                $value = null;
            }

            if ($value !== null && $value !== '' && $value !== 0 && $value !== '0') {
                $id = (int) $value;
                if ($id > 0) {
                    return $id;
                }
            }
        }

        // Symfony sessions expose attributes through ArrayAccess rather than get().
        if ($session instanceof \ArrayAccess && isset($session['authUserID'])) {
            $id = (int) $session['authUserID'];
            if ($id > 0) {
                return $id;
            }
        }

        return null;
    }
}