<?php

/**
 * SessionAccessor — version-agnostic access to the OpenEMR session.
 *
 * Why this exists
 * ---------------
 * The two supported OpenEMR branches expose mutually exclusive session APIs:
 *
 *   OpenEMR 8.2.0  SessionWrapperFactory::getWrapper()       exists, getActiveSession() does NOT
 *   OpenEMR 8.4.1  SessionWrapperFactory::getActiveSession() exists, getWrapper() does NOT
 *
 * Both are instance methods reached via getInstance() (the factory uses SingletonTrait);
 * neither may be called statically. This module previously hard-coded getActiveSession()
 * in eleven places and getWrapper() in one, so it only ran on one of the two branches and
 * died with "Call to undefined method" on the other.
 *
 * resolve() probes whichever accessor the running version provides. If neither exists the
 * caller receives null and must handle it — no session means no authenticated request, so
 * every caller fails closed rather than assuming an empty session is authenticated.
 *
 * Compatibility: OpenEMR 8.2.0 and 8.4.1.
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
}