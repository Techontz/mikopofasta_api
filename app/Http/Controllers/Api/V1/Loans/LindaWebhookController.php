<?php

namespace App\Http\Controllers\Api\V1\Loans;

use App\Http\Controllers\Controller;
use App\Services\PhoneBondLocks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/webhooks/linda - Linda Device Lock events (device.locked, device.unlocked, command.failed, webhook.test).
 * The X-DeviceLock-Signature header is checked over the raw body; each event id is applied once. Any 2xx acknowledges;
 * a processing error returns 500 so Linda retries.
 */
class LindaWebhookController extends Controller
{
    public function __invoke(Request $request, PhoneBondLocks $locks): JsonResponse
    {
        $locks->webhook($request->getContent(), $request->header('X-DeviceLock-Signature'));

        return response()->json(['received' => true]);
    }
}
