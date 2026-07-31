<?php

declare(strict_types=1);

namespace App\Domain\Loans\Services;

/**
 * Stands in for the bank's E-Mandate integration (§15.2
 * `POST /bank/e-mandate`).
 *
 * Like NidaRegistry in Phase 4, this is the seam: the real integration
 * dispatches an OTP through the bank and verifies the customer's reply.
 * Until then it accepts the frontend's fixed demo OTP, so the two sides agree.
 */
final class MandateGateway
{
    /**
     * The frontend's MANDATE_OTP in features/loans/actions.ts.
     */
    public const string SIMULATED_OTP = '654321';

    public function verifyOtp(string $otp): bool
    {
        return hash_equals(self::SIMULATED_OTP, $otp);
    }
}
