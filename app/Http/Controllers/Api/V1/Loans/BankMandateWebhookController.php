<?php

namespace App\Http\Controllers\Api\V1\Loans;

use App\Http\Controllers\Controller;
use App\Services\MandateCollections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Bank collection notifications for e-mandate debits (enhancement §4.2). The bank's payload format is not documented in the
 * system, so nothing is assumed: the endpoint works only once `integrations.bank_mandate.webhook_secret` (HMAC-SHA256 of the
 * body in X-Signature) and `collection_fields` (our field => the provider's field, dot notation) are configured, and answers
 * 503 until then. Repeated notifications return the first record.
 */
class BankMandateWebhookController extends Controller
{
    public function __invoke(Request $request, MandateCollections $collections): JsonResponse
    {
        $config = config('integrations.bank_mandate');
        $fields = $config['collection_fields'] ?? null;
        if (blank($config['webhook_secret'] ?? null) || ! is_array($fields) || blank($config['company_id'] ?? null)) {
            return response()->json(['message' => 'Bank mandate notifications are not configured.'], 503);
        }

        $expected = hash_hmac('sha256', $request->getContent(), (string) $config['webhook_secret']);
        abort_unless(hash_equals($expected, (string) $request->header('X-Signature', '')), 401, 'Invalid signature');

        $payload = $request->all();
        $value = fn (string $key): mixed => isset($fields[$key]) ? Arr::get($payload, $fields[$key]) : null;
        $collection = $collections->record((int) $config['company_id'], (string) ($config['provider'] ?? 'bank'), [
            'mandate_reference' => $value('mandate_reference'),
            'payment_reference' => (string) $value('payment_reference'),
            'amount' => $value('amount') ?? 0,
            'raw_status' => (string) ($value('status') ?? 'UNKNOWN'),
            'collection_date' => $value('collection_date'),
            'channel' => $value('channel'),
            'remarks' => $value('remarks'),
        ], null, $payload);

        return response()->json(['message' => 'Received', 'data' => ['id' => $collection->id, 'payment_status' => $collection->payment_status]]);
    }
}
