<?php

declare(strict_types=1);

namespace App\Http\Controllers\Loans;

use App\Domain\Auth\Enums\PermissionName;
use App\Domain\Loans\Actions\RecordApprovalDecisionAction;
use App\Domain\Loans\Enums\LoanApprovalDecision;
use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Loans\Services\LoanApprovalWorkflow;
use App\Domain\Organization\Services\BranchScopeGuard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\ApprovalDecisionRequest;
use App\Http\Resources\LoanApprovalDecisionResource;
use App\Http\Resources\LoanApprovalStageResource;
use App\Http\Resources\LoanResource;
use App\Models\Loan;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The approval chain over HTTP.
 *
 *     Loan Officer → Branch Manager → Zone Manager → Head Office Credit → Disbursement
 *
 * One decision endpoint rather than one per verb. The four decisions differ by
 * a single field, and splitting them across four routes would put the choice of
 * which route to call into the client — where a UI bug could send a hold to the
 * reject endpoint.
 *
 * Authorization is deliberately NOT a policy here. Which permission is needed
 * depends on which STAGE the loan is at, and that is a database row; a policy
 * method would have to re-derive the stage to answer, duplicating the workflow.
 * So the guard is `LoanApprovalWorkflow::assertCanDecide()`, which is the same
 * rule the read endpoint reports and refuses with the same message.
 */
final class LoanApprovalController extends Controller
{
    public function __construct(private readonly BranchScopeGuard $guard) {}

    /**
     * GET /api/v1/loans/{loan}/approval — where this loan is, and what the
     * caller may do about it.
     *
     * The UI renders its buttons off `availableDecisions`, so the same rule
     * that refuses a decision is the one that decides whether it is offered.
     * A read-only endpoint: asking what you may do never changes anything.
     */
    public function show(Request $request, Loan $loan, LoanApprovalWorkflow $workflow): JsonResponse
    {
        $this->authorize('view', $loan);
        $actor = $this->actor($request);
        $this->guard->authorizeBranchId($actor, $loan->branch_id, Loan::class);

        $stage = $workflow->currentStage($loan);
        $canDecide = $stage !== null && $workflow->canDecide($loan, $stage, $actor);

        return ApiResponse::data([
            'loanId' => (string) $loan->getKey(),
            'status' => $loan->status->value,
            'currentStage' => $stage === null ? null : new LoanApprovalStageResource($stage),
            /*
             * THIS loan's chain, not the institution's — D4.
             *
             * A loan raised at a branch with no zone never had a zone stage, and
             * showing one on its approval panel would tell the officer their
             * file still has a tier to clear that it will never be sent to.
             */
            'chain' => LoanApprovalStageResource::collection($workflow->chainFor($loan)),
            'isOwnApplication' => $loan->created_by === $actor->getKey(),
            'canDecide' => $canDecide,
            'availableDecisions' => $this->availableDecisions($loan, $canDecide, $actor),
            'holdResumeStatus' => $loan->hold_resume_status?->value,
            'decisions' => LoanApprovalDecisionResource::collection(
                $loan->approvalDecisions()->with('decider')->get(),
            ),
        ]);
    }

    /**
     * POST /api/v1/loans/{loan}/approval/decide
     */
    public function decide(
        ApprovalDecisionRequest $request,
        Loan $loan,
        RecordApprovalDecisionAction $action,
    ): JsonResponse {
        $this->authorize('view', $loan);
        $actor = $this->actor($request);

        /*
         * §13, and the reason the Head Office Credit tier can exist at all.
         *
         * Cross-branch REVIEW is never implied by visibility — it needs the
         * explicit `loans.review_cross_branch` grant. Without this carve-out a
         * head-office approver could not decide a branch's loan, which would
         * make the last two tiers of the client's chain unreachable for anyone
         * not sitting in the originating branch. The same guard telcoVerify
         * uses, for the same reason.
         */
        if ($loan->branch_id !== $actor->branch_id
            && ! $actor->hasPermission(PermissionName::LoansReviewCrossBranch)) {
            $this->guard->authorizeBranchId($actor, $loan->branch_id, Loan::class);
        }

        $reason = $request->reason();

        $updated = match ($request->decision()) {
            LoanApprovalDecision::Approved => $action->approve($loan, $actor, $reason),
            LoanApprovalDecision::Rejected => $action->reject($loan, $actor, (string) $reason),
            LoanApprovalDecision::ReturnedForModification => $action->returnForModification($loan, $actor, (string) $reason),
            LoanApprovalDecision::Held => $action->hold($loan, $actor, (string) $reason),
            LoanApprovalDecision::Released => $action->release($loan, $actor, $reason),
        };

        return ApiResponse::data(new LoanResource($updated->load(['customer', 'product', 'schedules'])));
    }

    /**
     * POST /api/v1/loans/{loan}/approval/resubmit — the officer's side of a
     * return.
     *
     * Gated on `loans.create` rather than on an approval permission: this is
     * the origination action, taken by the person who raised the file.
     */
    public function resubmit(Request $request, Loan $loan, RecordApprovalDecisionAction $action): JsonResponse
    {
        $this->authorize('create', Loan::class);
        $actor = $this->actor($request);
        $this->guard->authorizeBranchId($actor, $loan->branch_id, Loan::class);

        $note = $request->input('note');

        $updated = $action->resubmit($loan, $actor, is_string($note) && trim($note) !== '' ? trim($note) : null);

        return ApiResponse::data(new LoanResource($updated->load(['customer', 'product', 'schedules'])));
    }

    /**
     * What this actor may actually do to this loan right now.
     *
     * Release is the odd one out: a held loan is at no stage, so it is offered
     * on the hold permission rather than on the stage's own grant — the person
     * putting a decision back in front of an approver is not thereby approving
     * it.
     *
     * @return list<string>
     */
    private function availableDecisions(Loan $loan, bool $canDecide, User $actor): array
    {
        if ($loan->status === LoanStatus::OnHold) {
            return $actor->hasPermission(PermissionName::LoansHold)
                ? [LoanApprovalDecision::Released->value]
                : [];
        }

        if ($loan->status === LoanStatus::ReturnedForModification) {
            return $actor->hasPermission(PermissionName::LoansCreate)
                ? ['resubmit']
                : [];
        }

        if (! $canDecide) {
            return [];
        }

        $decisions = [
            LoanApprovalDecision::Approved->value,
            LoanApprovalDecision::Rejected->value,
        ];

        /*
         * Hold and return need their own grant. An approver who may clear a
         * loan is not automatically someone who may park it indefinitely — the
         * two are separately auditable and separately granted.
         */
        if ($actor->hasPermission(PermissionName::LoansHold)) {
            $decisions[] = LoanApprovalDecision::ReturnedForModification->value;
            $decisions[] = LoanApprovalDecision::Held->value;
        }

        return $decisions;
    }
}
