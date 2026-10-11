<?php

/**
 * AclGuard — central authorization gate for the AI Assistant module.
 *
 * The module ships no ACL of its own. Authorization is delegated to native OpenEMR
 * permissions so administrators have nothing extra to configure:
 *
 *   - canUse():  the same permission the native SOAP form uses. It is resolved from the
 *                form registry (registry.aco_spec for directory 'soap' -> "encounters|notes")
 *                through AclMain::aclCheckForm('soap', '', ['write', 'addonly']). Covers the
 *                launcher button, the SOAP-AI editor, dictation/transcription, draft
 *                generation and the chat panel.
 *   - canAdmin(): the native OpenEMR superuser permission,
 *                AclMain::aclCheckCore('admin', 'super'). Covers the settings page and
 *                every action on it.
 *
 * Every check is wrapped so that ANY Throwable results in false: authorization fails
 * closed. The AclMain calls sit behind two small overridable seams so the guard is
 * unit-testable without OpenEMR.
 *
 * Compatibility: OpenEMR 8.2.0+ (PHP 8.2).
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Security;

use OpenEMR\Common\Acl\AclMain;

class AclGuard
{
    /**
     * The native SOAP form directory. OpenEMR resolves its permission through the form
     * registry, where the registered aco_spec for 'soap' is "encounters|notes".
     */
    private const SOAP_FORM = 'soap';

    /**
     * Return values accepted for the native SOAP form: a user holding write or addonly
     * may create/edit an encounter note, which is exactly what the assistant edits.
     *
     * @var array<int, string>
     */
    private const SOAP_RETURN_VALUES = ['write', 'addonly'];

    /**
     * May the current user use the assistant (launcher, editor, dictation, draft, chat)?
     *
     * Mirrors the native SOAP form ACL exactly. Denies by default on any Throwable.
     */
    public static function canUse(): bool
    {
        try {
            return static::checkForm(self::SOAP_FORM, self::SOAP_RETURN_VALUES);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * May the current user administer the module (settings page and its actions)?
     *
     * Uses the native OpenEMR superuser permission. Denies by default on any Throwable.
     */
    public static function canAdmin(): bool
    {
        try {
            return static::checkCore('admin', 'super');
        } catch (\Throwable $e) {
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Overridable seams — the only place that touches OpenEMR's AclMain.
    // -------------------------------------------------------------------------

    /**
     * Checks an encounter form type ACL. Overridden in tests to avoid OpenEMR.
     *
     * @param array<int, string> $returnValues
     */
    protected static function checkForm(string $formdir, array $returnValues): bool
    {
        return (bool) AclMain::aclCheckForm($formdir, '', $returnValues);
    }

    /**
     * Checks a core section/object ACL. Overridden in tests to avoid OpenEMR.
     */
    protected static function checkCore(string $section, string $value): bool
    {
        return (bool) AclMain::aclCheckCore($section, $value);
    }
}
