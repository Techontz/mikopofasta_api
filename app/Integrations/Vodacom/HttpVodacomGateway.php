<?php

namespace App\Integrations\Vodacom;

use App\Models\LoanDisbursement;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Real Vodacom driver stub.
 * Inferred: POST {base_url}/kyc/lookup {msisdn} → {name}; POST {base_url}/b2c/disbursements
 * {batch_id, msisdn, amount, callback_url} → {accepted, reference}; callbacks signed with
 * HMAC-SHA256(raw body, callback_secret) in X-Signature. Map to the contracted API when issued.
 */
class HttpVodacomGateway implements VodacomGateway
{
    public function kycLookup(string $phone, string $nameOnFile): TelcoKycResult
    {
        try {
            $response = $this->client()->post('/kyc/lookup', ['msisdn' => $phone]);
        } catch (ConnectionException) {
            return new TelcoKycResult(false, null, 'Network error, please try again');
        }

        if ($response->notFound()) {
            return new TelcoKycResult(false, null, 'Phone number is not registered on Vodacom');
        }

        return new TelcoKycResult(true, strtoupper((string) $response->throw()->json('name')));
    }

    public function requestDisbursement(LoanDisbursement $disbursement): DisbursementRequestResult
    {
        try {
            $response = $this->client()->post('/b2c/disbursements', [
                'batch_id' => $disbursement->batch_id,
                'msisdn' => $disbursement->phone,
                'amount' => (float) $disbursement->amount,
                'callback_url' => url('/api/webhooks/vodacom/disbursement-status'),
            ]);
        } catch (ConnectionException) {
            return new DisbursementRequestResult(false, null, 'Network error');
        }

        if ($response->failed()) {
            return new DisbursementRequestResult(false, null, (string) ($response->json('message') ?? 'API error'));
        }

        return new DisbursementRequestResult((bool) $response->json('accepted', true), $response->json('reference'));
    }

    public function verifyCallback(Request $request): bool
    {
        $secret = $this->secret();
        $signature = (string) $request->header('X-Signature', '');

        if ($secret === null || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $request->getContent(), $secret), preg_replace('/^sha256=/', '', $signature));
    }

    public function parseCallback(Request $request): DisbursementCallback
    {
        $data = Validator::make($request->json()->all(), [
            'batch_id' => ['required', 'string', 'max:40'],
            'status' => ['required', 'string', 'max:20'],
            'transaction_id' => ['nullable', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:255'],
        ])->validate();

        return new DisbursementCallback(
            batchId: $data['batch_id'],
            success: in_array(strtoupper($data['status']), ['SUCCESS', 'SUCCESSFUL', 'COMPLETED'], true),
            transactionId: $data['transaction_id'] ?? null,
            reason: $data['reason'] ?? null,
            payload: $request->json()->all(),
        );
    }

    public function portalUrl(): ?string
    {
        return config('integrations.vodacom.portal_url') ?: null;
    }

    protected function secret(): ?string
    {
        $secret = config('integrations.vodacom.callback_secret');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    private function client(): PendingRequest
    {
        $config = config('integrations.vodacom');
        if (empty($config['base_url']) || empty($config['api_key'])) {
            throw new RuntimeException('Vodacom gateway is not configured.');
        }

        return Http::withToken($config['api_key'])->timeout(20)->baseUrl(rtrim($config['base_url'], '/'));
    }
}
