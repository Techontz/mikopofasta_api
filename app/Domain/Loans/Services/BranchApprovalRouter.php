<?php

declare(strict_types=1);

namespace App\Domain\Loans\Services;

use App\Models\Branch;
use App\Models\BranchApprovalRoute;
use App\Models\Loan;
use App\Models\LoanApprovalRoute;
use App\Models\LoanApprovalStage;
use Illuminate\Support\Collection;

/**
 * Which approval stages a given branch's applications must pass through — D4.
 *
 *     A branch WITH a zone:    Officer → Branch Manager → Zone → Credit → Finance
 *     A branch WITHOUT a zone: Officer → Branch Manager → Credit → Finance
 *
 * ## Two questions, deliberately kept apart
 *
 * `routeFor(Branch)` answers "what would an application raised here walk today"
 * — live configuration, used when a loan is created and by the admin screen.
 *
 * `stagesFor(Loan)` answers "what is THIS loan walking" — the snapshot taken
 * when it was raised. Every workflow decision reads this one. Configuration
 * changes must never reach a loan already in flight: moving a branch into a
 * zone on Tuesday would otherwise insert a zone review into applications raised
 * on Monday that had already cleared their branch manager, sending them
 * backwards to an approver who had already seen them.
 *
 * ## How the default is decided, without hardcoding a stage
 *
 * `requires_branch_zone` on the stage says "this one applies only where the
 * branch has a zone". That is the rule expressed as data. A branch-level row in
 * `branch_approval_routes` overrides it either way, which is what makes the
 * routing "completely configurable from Admin" rather than merely derived.
 *
 * Precedence, highest first:
 *   1. An explicit `branch_approval_routes` row for this branch and stage.
 *   2. `requires_branch_zone` → include only if the branch belongs to a zone.
 *   3. Otherwise include.
 *
 * Inactive stages are excluded before any of that: a stage switched off
 * institution-wide is not something a branch can opt back into.
 */
final class BranchApprovalRouter
{
    /**
     * The chain an application raised at this branch would walk today.
     *
     * @return Collection<int, LoanApprovalStage>
     */
    public function routeFor(Branch $branch): Collection
    {
        $overrides = BranchApprovalRoute::query()
            ->where('branch_id', $branch->getKey())
            ->pluck('is_required', 'loan_approval_stage_id');

        return LoanApprovalStage::chain()
            ->filter(function (LoanApprovalStage $stage) use ($branch, $overrides): bool {
                $id = $stage->getKey();

                if ($overrides->has($id)) {
                    return (bool) $overrides->get($id);
                }

                if ($stage->requires_branch_zone) {
                    return $branch->zone_id !== null;
                }

                return true;
            })
            ->values();
    }

    /**
     * Records the route a loan will walk, at application time.
     *
     * Idempotent: re-running it on a loan that already has a route leaves the
     * original alone. A resubmitted application walks the chain it was given,
     * not a freshly computed one — the officer corrected a figure, they did not
     * raise a new agreement.
     */
    public function snapshotFor(Loan $loan): void
    {
        if (LoanApprovalRoute::query()->where('loan_id', $loan->getKey())->exists()) {
            return;
        }

        $loan->loadMissing('branch');

        if ($loan->branch === null) {
            return;
        }

        foreach ($this->routeFor($loan->branch) as $stage) {
            LoanApprovalRoute::query()->create([
                'loan_id' => $loan->getKey(),
                'loan_approval_stage_id' => $stage->getKey(),
                'sequence' => $stage->sequence,
            ]);
        }
    }

    /**
     * The chain THIS loan is walking, in order.
     *
     * Falls back to the live chain when the loan has no snapshot — every loan
     * raised before this batch shipped. Those keep behaving exactly as they did,
     * which is the backward-compatible reading of "do not silently reroute".
     *
     * Stages that have since been deactivated are dropped even from a snapshot:
     * switching a stage off institution-wide means nobody is manning it, and
     * routing a loan to an unmanned stage would strand it.
     *
     * @return Collection<int, LoanApprovalStage>
     */
    public function stagesFor(Loan $loan): Collection
    {
        $snapshot = LoanApprovalRoute::query()
            ->where('loan_id', $loan->getKey())
            ->orderBy('sequence')
            ->pluck('loan_approval_stage_id');

        if ($snapshot->isEmpty()) {
            return LoanApprovalStage::chain();
        }

        return LoanApprovalStage::chain()
            ->filter(fn (LoanApprovalStage $stage): bool => $snapshot->contains($stage->getKey()))
            ->values();
    }
}
