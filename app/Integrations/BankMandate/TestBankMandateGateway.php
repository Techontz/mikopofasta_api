<?php

namespace App\Integrations\BankMandate;

use App\Models\LoanMandate;

/**
 * Deterministic test driver: OTP "123456" activates the mandate, "000000" simulates a network
 * failure and anything else is a wrong OTP. Account numbers starting with "000" are rejected by the bank.
 */
class TestBankMandateGateway implements BankMandateGateway
{
    public const OTP = '123456';

    public function create(LoanMandate $mandate): MandateResult
    {
        if (str_starts_with($mandate->account_number, '000')) {
            return new MandateResult(false, null, 'Bank account not found');
        }

        return new MandateResult(true, 'EM'.str_pad((string) $mandate->id, 8, '0', STR_PAD_LEFT));
    }

    public function verifyOtp(LoanMandate $mandate, string $otp): MandateResult
    {
        return match ($otp) {
            self::OTP => new MandateResult(true, $mandate->mandate_reference),
            '000000' => new MandateResult(false, null, 'Network issue, please retry OTP'),
            default => new MandateResult(false, null, 'Wrong OTP'),
        };
    }
}
