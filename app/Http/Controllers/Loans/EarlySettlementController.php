<?php

declare(strict_types=1);

namespace App\Http\Controllers\Loans;

use App\Domain\Loans\Actions\SettleLoanEarlyAction;
use App\Domain\Organization\Services\BranchScopeGuard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\SettleLoanEarlyRequest;
use App\Http\Resources\LoanResource;
use App\Models\Loan;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Close Loan Early" — client Decision 1, Option B.
 *
 * Two endpoints, and the split matters. The GET is what an officer shows a
 * customer across the counter; the POST is the decision. They compute the
 * figure through the same quoter, so the number quoted is the number charged —
 * and the POST re-quotes rather than trusting what the screen sent, because a
 * penalty accruing in between would otherwise close a loan for less than it
 * owed.
 */
final class EarlySettlementController extends Controller
{
    public function __construct(private readonly BranchScopeGuard $guard) {}

    /**
     * GET /api/v1/loans/{loan}/early-settlement
     *
     * Read-only, and gated on `loans.view` rather than the settlement grant: an
     * officer who cannot settle a loan can still legitimately need to tell a
     * customer what settling would cost.
     */
    public function quote(Request $request, Loan $loan, SettleLoanEarlyAction $action): JsonResponse
    {
        $this->authorize('view', $loan);
        $this->guard->authorizeBranchId($this->actor($request), $loan->branch_id, Loan::class);

        return ApiResponse::data($action->quote($loan)->toArray());
    }

    /**
     * POST /api/v1/loans/{loan}/early-settlement
     */
    public function settle(
        SettleLoanEarlyRequest $request,
        Loan $loan,
        SettleLoanEarlyAction $action,
    ): JsonResponse {
        $this->authorize('settleEarly', $loan);
        $actor = $this->actor($request);
        $this->guard->authorizeBranchId($actor, $loan->branch_id, Loan::class);

        $updated = $action->handle(
            $loan,
            Money::of((string) ($request->validated('amount') ?? '0.00')),
            $actor,
        );

        /*
         * The settlement relations are loaded on the way out so the officer's
         * screen receives the completed record in the same response that
         * performed it — the reference and the waived figure are exactly what
         * they need to read back a moment after clicking.
         */
        return ApiResponse::data(new LoanResource($updated->load([
            'customer', 'product', 'schedules', 'earlySettledBy', 'earlySettlementPayment',
        ])));
    }
}
