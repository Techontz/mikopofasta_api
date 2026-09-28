<?php

namespace App\Http\Controllers\Api\V1\Approvals;

use App\Http\Controllers\Api\V1\ApiController;
use App\Services\Approvals\PendingApprovals;
use Illuminate\Http\JsonResponse;

/**
 * Pending Approvals (C6): every item waiting for a checker across the two-step workflows the signed-in user may see, grouped
 * by workflow with counts. Read-only — approvals happen on the module page each row links to.
 */
class PendingApprovalController extends ApiController
{
    public function index(PendingApprovals $pending): JsonResponse
    {
        $this->authorizeAny('approvals.view');

        return response()->json(['data' => $pending->forEmployee($this->currentEmployee())]);
    }
}
