<?php

namespace App\Integrations\BankMandate;

use App\Models\LoanMandate;

/**
 * Bank e-mandate connector (Documents: POST /bank/e-mandate, POST /bank/e-mandate/verify-otp).
 * Selected by config('integrations.bank_mandate.driver').
 */
interface BankMandateGateway
{
    /**
     * Register the mandate with the bank; the bank sends an OTP to the account holder.
     */
    public function create(LoanMandate $mandate): MandateResult;

    public function verifyOtp(LoanMandate $mandate, string $otp): MandateResult;
}
