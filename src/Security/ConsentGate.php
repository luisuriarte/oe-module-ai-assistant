<?php

/**
 * ConsentGate — enforces the admin acknowledgement gate before any PHI leaves the host.
 *
 * Why this exists
 * ---------------
 * SettingsManager::isConfigured() treats `consent_acknowledged` as a requirement, but
 * that flag was never actually consulted on the request path: SoapFormScriptListener
 * deliberately injected the toolbar regardless, and DraftController / TranscribeController
 * sent patient context to the active provider unconditionally. In practice the flag was
 * advisory only.
 *
 * This module transmits protected health information to third-party AI providers
 * (OpenAI, Anthropic, Google, xAI). An admin acknowledgement is a deployment-level
 * attestation, not per-patient consent, but it must at minimum be a hard gate: if the
 * admin has not acknowledged it, the module must not transmit anything.
 *
 * Enforcement is deliberately server-side. The JavaScript toolbar also checks this so the
 * clinician gets an explanation instead of an opaque HTTP 403, but that check is cosmetic —
 * every transmitting endpoint calls assertConsentGranted() before building any payload.
 *
 * Compatibility: OpenEMR 8.2.0+ (PHP 8.2).
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\AiAssistant\Security;

use OpenEMR\Modules\AiAssistant\Settings\SettingsManager;

class ConsentGate
{
    private SettingsManager $settings;

    public function __construct(?SettingsManager $settings = null)
    {
        $this->settings = $settings ?? new SettingsManager();
    }

    /**
     * Returns true when the admin has acknowledged the PHI transmission disclosure.
     *
     * Any unexpected failure resolves to FALSE (fail closed). A transient database error
     * must never be the reason PHI reaches a third-party provider.
     */
    public function isGranted(): bool
    {
        try {
            return $this->settings->isConsentGiven();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Returns true when the module may transmit PHI to the active provider.
     *
     * Requires BOTH the admin acknowledgement and a usable provider key. The provider key
     * check is a courtesy that produces a clearer error than a provider 401 later on.
     */
    public function isTransmissionAllowed(): bool
    {
        try {
            if (!$this->settings->isConsentGiven()) {
                return false;
            }
            return $this->settings->isConfigured();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Short machine-readable reason for a denial, for audit records.
     * Never contains clinical content.
     */
    public function denialReason(): string
    {
        try {
            if (!$this->settings->isConsentGiven()) {
                return 'consent_not_acknowledged';
            }
            return 'provider_not_configured';
        } catch (\Throwable) {
            return 'consent_check_failed';
        }
    }

    /**
     * Human-readable, translatable message for a denial.
     */
    public function denialMessage(): string
    {
        return match ($this->denialReason()) {
            'consent_not_acknowledged' => xlt(
                'The AI Assistant administrator has not acknowledged the patient data disclosure.'
                . ' No data was sent. Ask an administrator to enable AI features in'
                . ' Administration -> Modules -> AI Assistant -> Configure.'
            ),
            'provider_not_configured' => xlt(
                'No API key is saved for the active AI provider. No data was sent.'
                . ' An administrator must configure the provider key.'
            ),
            default => xlt('AI transmission is currently blocked by the module safety gate.'),
        };
    }
}