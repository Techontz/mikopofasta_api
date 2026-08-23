<?php

declare(strict_types=1);

namespace App\Domain\Customers\Services;

use Illuminate\Contracts\Config\Repository;

/**
 * Whether the external identity checks can be performed at all.
 *
 * Read by the KYC evaluator, by the registration validator and by the wizard's
 * requirements endpoint, so that all three say the same thing about the same
 * deployment. The distinction this exists to preserve is the one the client was
 * explicit about:
 *
 *     "Identity information captured"  ≠  "NIDA verified"
 *     "Phone number captured"          ≠  "SMS/OTP verified"
 *
 * Nothing in this codebase may collapse those. A customer who typed a National
 * ID number has given us an identity document; they have not been verified
 * against the national registry, and until the integration exists no code path
 * is allowed to claim otherwise. See config/kyc.php.
 */
final class ExternalVerificationStatus
{
    public function __construct(private readonly Repository $config) {}

    public function nidaAvailable(): bool
    {
        return (bool) $this->config->get('kyc.nida.available', false);
    }

    public function otpAvailable(): bool
    {
        return (bool) $this->config->get('kyc.otp.available', false);
    }

    /**
     * What the officer is told about each integration, in words rather than a
     * boolean, so the UI does not have to invent the sentence.
     *
     * @return array{
     *     nida: array{available: bool, note: string},
     *     otp: array{available: bool, note: string}
     * }
     */
    public function summary(): array
    {
        return [
            'nida' => [
                'available' => $this->nidaAvailable(),
                'note' => $this->nidaAvailable()
                    ? 'National ID numbers are checked against the NIDA registry.'
                    : 'NIDA registry integration is not configured. Identity documents are captured and recorded, but not externally verified.',
            ],
            'otp' => [
                'available' => $this->otpAvailable(),
                'note' => $this->otpAvailable()
                    ? 'A one-time code is sent to the customer\'s phone.'
                    : 'SMS gateway is not configured. Phone numbers are captured and recorded, but no code is sent and none is verified.',
            ],
        ];
    }
}
