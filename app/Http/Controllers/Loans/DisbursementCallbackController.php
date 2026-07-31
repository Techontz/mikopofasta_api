<?php

declare(strict_types=1);

namespace App\Http\Controllers\Loans;

use App\Domain\Loans\Actions\SettleDisbursementAction;
use App\Domain\Loans\Enums\DisbursementStatus;
use App\Domain\Loans\Exceptions\LoanStateException;
use App\Domain\Organization\Services\BranchScopeGuard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\SettleDisbursementRequest;
use App\Http\Resources\DisbursementBatchResource;
use App\Http\Resources\LoanResource;
use App\Models\DisbursementBatch;
use App\Models\Loan;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The disbursement callback — §15.2's
 * `POST /webhooks/vodacom/disbursement-status`.
 *
 * This is the moment §6 reserves for the ledger: "No ledger entry exists until
 * a disbursement batch reaches success." Phase 5 could prepare and retry a
 * batch but could not settle one, because settling means posting.
 *
 * Two routes reach the same method, which is deliberate rather than
 * duplication:
 *
 *   - the signed provider webhook (§15.2), the production path; and
 *   - an authenticated `POST /loans/{loan}/settle-disbursement` requiring
 *     `loans.disburse`, which is what the frontend's loan actions panel calls
 *     (`settleDisbursement(loanId, success)`) and therefore part of the
 *     contract.
 *
 * Both funnel into SettleDisbursementAction, so there is exactly one place
 * where a loan becomes active and exactly one place that posts the entry.
 */
final class DisbursementCallbackController extends Controller
{
    public function __construct(private readonly BranchScopeGuard $guard) {}

    /**
     * POST /api/v1/loans/{loan}/settle-disbursement
     */
    public function settle(SettleDisbursementRequest $request, Loan $loan, SettleDisbursementAction $action): JsonResponse
    {
        $this->authorize('disburse', $loan);

        $actor = $this->actor($request);
        $this->guard->authorizeBranchId($actor, $loan->branch_id, Loan::class);

        return $this->apply($loan, $action, $actor, $request->validated());
    }

    /**
     * POST /webhooks/vodacom/disbursement-status
     *
     * Identified by `batchReference` rather than a loan id — a provider knows
     * the reference it was given and nothing else about our data model.
     */
    public function webhook(SettleDisbursementRequest $request, SettleDisbursementAction $action): JsonResponse
    {
        $batch = DisbursementBatch::query()
            ->with('loan')
            ->where('batch_reference', (string) $request->validated('batchReference'))
            ->firstOrFail();

        // Provider callbacks carry no user. Attributing the posting to the
        // officer who requested the batch keeps `created_by` meaningful:
        // the entry traces back to the person who initiated the money movement.
        $actor = $batch->requestedBy ?? User::query()->orderBy('id')->firstOrFail();

        return $this->apply($batch->loan, $action, $actor, $request->validated(), $batch);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function apply(
        Loan $loan,
        SettleDisbursementAction $action,
        User $actor,
        array $payload,
        ?DisbursementBatch $batch = null,
    ): JsonResponse {
        $batch ??= $this->pendingBatch($loan);

        $loan = ($payload['success'] ?? false)
            ? $action->succeed($batch, $actor)
            : $action->fail(
                $batch,
                (string) ($payload['failureReason'] ?? 'Provider rejected the transfer.'),
                $actor,
            );

        return ApiResponse::data(
            new LoanResource($loan),
            ['batch' => new DisbursementBatchResource($batch->fresh())],
        );
    }

    /**
     * The batch a callback refers to when it names a loan instead of a
     * reference: the latest attempt still in flight.
     */
    private function pendingBatch(Loan $loan): DisbursementBatch
    {
        $batch = $loan->disbursementBatches()
            ->where('status', DisbursementStatus::Pending)
            ->latest('attempt_number')
            ->first();

        if ($batch === null) {
            throw LoanStateException::noPendingDisbursement($loan->loan_number);
        }

        return $batch;
    }
}
