<?php

namespace App\Http\Controllers\Api\V1\Loans;

use App\Http\Controllers\Controller;
use App\Integrations\Vodacom\VodacomGateway;
use App\Services\LoanWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Public Vodacom callback (Documents: POST /webhooks/vodacom/disbursement-status), HMAC-signed.
 * Idempotent; processing errors return 500 so Vodacom retries.
 */
class VodacomDisbursementWebhookController extends Controller
{
    public function __invoke(Request $request, VodacomGateway $vodacom, LoanWorkflow $workflow): JsonResponse
    {
        if (! $vodacom->verifyCallback($request)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $callback = $vodacom->parseCallback($request);

        try {
            $result = $workflow->handleCallback($callback);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Vodacom disbursement callback failed', ['batch_id' => $callback->batchId, 'error' => $exception->getMessage()]);

            return response()->json(['message' => 'Callback could not be processed, please retry.'], 500);
        }

        return response()->json($result, $result['status'] === 'UNKNOWN_BATCH' ? 404 : 200);
    }
}
