<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Http\Controllers\Controller;
use App\Integrations\Payments\PaymentWebhookConnector;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Public provider callback (Documents: POST /webhooks/payments). Signed with the shared webhook secret.
 * Responses: PAYMENT_SUCCESS (allocated), SUSPENSE (unmatched), DUPLICATE (ignored, flagged in audit log).
 * Processing errors return 500 so the provider retries; retries are safe because the endpoint is idempotent.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentWebhookConnector $connector, PaymentService $payments): JsonResponse
    {
        if (! $connector->verify($request)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $notification = $connector->parse($request);

        try {
            $result = $payments->receive($notification);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Payment webhook failed', ['transaction_id' => $notification->transactionId, 'channel' => $notification->channel, 'error' => $exception->getMessage()]);

            return response()->json(['message' => 'Payment could not be processed, please retry.'], 500);
        }

        return response()->json([
            'status' => $result['status'],
            'receipt_number' => $result['payment']->receipt_number,
            'transaction_id' => $result['payment']->transaction_id,
        ]);
    }
}
