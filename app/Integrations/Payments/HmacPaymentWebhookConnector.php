<?php

namespace App\Integrations\Payments;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Real driver: the provider signs the raw JSON body with HMAC-SHA256 using the shared
 * secret (config integrations.payments.webhook_secret) in the X-Signature header.
 * Inferred: the payload follows the Documents example; map provider-specific field names here
 * when the aggregator contract is signed.
 */
class HmacPaymentWebhookConnector implements PaymentWebhookConnector
{
    public function verify(Request $request): bool
    {
        $secret = $this->secret();
        $signature = (string) $request->header('X-Signature', '');

        if ($secret === null || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, preg_replace('/^sha256=/', '', $signature));
    }

    public function parse(Request $request): PaymentNotification
    {
        $data = Validator::make($request->json()->all(), [
            'transaction_id' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:1'],
            'channel' => ['required', 'string', 'max:30'],
            'reference' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'paid_on' => ['nullable', 'date'],
        ])->validate();

        return new PaymentNotification(
            transactionId: (string) $data['transaction_id'],
            amount: round((float) $data['amount'], 2),
            channel: strtoupper((string) $data['channel']),
            reference: isset($data['reference']) && trim((string) $data['reference']) !== '' ? trim((string) $data['reference']) : null,
            phone: $data['phone'] ?? null,
            paidOn: $data['paid_on'] ?? null,
            payload: $request->json()->all(),
        );
    }

    protected function secret(): ?string
    {
        $secret = config('integrations.payments.webhook_secret');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }
}
