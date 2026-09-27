<?php

namespace App\Integrations\BankMandate;

use App\Models\LoanMandate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Real bank e-mandate driver stub.
 * Inferred: POST {base_url}/mandates {account_number, account_name, bank_name, reference} → {mandate_reference};
 * POST {base_url}/mandates/{reference}/verify-otp {otp} → 200 when activated. Map to the bank's API when issued.
 */
class HttpBankMandateGateway implements BankMandateGateway
{
    public function create(LoanMandate $mandate): MandateResult
    {
        try {
            $response = $this->client()->post('/mandates', [
                'account_number' => $mandate->account_number,
                'account_name' => $mandate->account_name,
                'bank_name' => $mandate->bank_name,
                'reference' => 'LOAN-'.$mandate->loan_id,
            ]);
        } catch (ConnectionException) {
            return new MandateResult(false, null, 'Network issue');
        }

        return $response->successful()
            ? new MandateResult(true, (string) $response->json('mandate_reference'))
            : new MandateResult(false, null, (string) ($response->json('message') ?? 'Bank API error'));
    }

    public function verifyOtp(LoanMandate $mandate, string $otp): MandateResult
    {
        try {
            $response = $this->client()->post('/mandates/'.$mandate->mandate_reference.'/verify-otp', ['otp' => $otp]);
        } catch (ConnectionException) {
            return new MandateResult(false, null, 'Network issue');
        }

        return $response->successful()
            ? new MandateResult(true, $mandate->mandate_reference)
            : new MandateResult(false, null, (string) ($response->json('message') ?? 'Wrong OTP'));
    }

    private function client(): PendingRequest
    {
        $config = config('integrations.bank_mandate');
        if (empty($config['base_url']) || empty($config['api_key'])) {
            throw new RuntimeException('Bank e-mandate gateway is not configured.');
        }

        return Http::withToken($config['api_key'])->timeout(20)->baseUrl(rtrim($config['base_url'], '/'));
    }
}
