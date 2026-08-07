<?php

declare(strict_types=1);

namespace App\Domain\Loans\Services;

use App\Domain\Loans\Enums\EMandateStatus;
use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Loans\Exceptions\LoanApprovalException;
use App\Models\Loan;
use App\Models\LoanApprovalStage;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The approval chain, read once and answered from.
 *
 *     Loan Officer → Branch Manager → Zone Manager → Head Office Credit → Disbursement
 *
 * Everything about "where is this loan and who may act on it" is decided here.
 * Actions, controllers and the frontend all ask this class; none of them knows
 * how many stages there are or what order they run in, which is what lets the
 * chain be configuration.
 *
 * ## What it decides, and what it deliberately does not
 *
 * Decides: which stage a loan is at, whether a given user may act on it, and
 * what status a decision moves it to.
 *
 * Does not: write anything. It has no transaction, posts no audit and moves no
 * loan — RecordApprovalDecisionAction does all of that. Keeping the rules
 * separate from the writing is what makes "may this teller approve this loan"
 * answerable by the read-only endpoint the UI uses to decide which buttons to
 * render, without any risk of that question changing something.
 */
final class LoanApprovalWorkflow
{
    public function __construct(private readonly BranchApprovalRouter $router) {}

    /** The stage this loan is waiting on, or null if it is not in the chain. */
    public function currentStage(Loan $loan): ?LoanApprovalStage
    {
        return LoanApprovalStage::forStatus($loan->status);
    }

    /**
     * The stage after `$stage` **on this loan's route**.
     *
     * The route is the loan's own — the snapshot taken when it was raised (D4).
     * A branch with no zone has no zone stage in its route at all, so the branch
     * manager's approval falls straight through to Head Office Credit without
     * anything here testing for a zone.
     *
     * Takes the loan rather than only the stage because the answer genuinely
     * depends on it: two loans sitting at the same stage, raised at different
     * branches, go to different places next.
     */
    public function nextStage(Loan $loan, LoanApprovalStage $stage): ?LoanApprovalStage
    {
        return $this->router->stagesFor($loan)
            ->first(fn (LoanApprovalStage $candidate): bool => $candidate->sequence > $stage->sequence);
    }

    /**
     * Where an approval at `$stage` sends the loan.
     *
     * Three outcomes, in order of precedence:
     *
     *   1. The next stage requires a live e-mandate and this loan needs one →
     *      the OTP flow first. §10's conditional branch, decided from the
     *      product SNAPSHOT taken at application, so a product edited since
     *      cannot reroute a loan already in flight.
     *   2. There is a next stage → that stage's status.
     *   3. There is not → Pending Finance, and the loan leaves the chain for
     *      disbursement.
     */
    public function statusAfterApproval(Loan $loan, LoanApprovalStage $stage): LoanStatus
    {
        $next = $this->nextStage($loan, $stage);

        if ($next === null) {
            return LoanStatus::PendingFinance;
        }

        if ($next->requires_mandate_before && $this->needsMandate($loan)) {
            return LoanStatus::MandatePendingOtp;
        }

        return $next->loan_status;
    }

    /**
     * The stage a loan re-enters the chain at after being returned and
     * resubmitted — the first one **on its own route**.
     *
     * Its own, not the institution's: a resubmitted application restarts from
     * the Loan Officer and walks the chain it was given, so a loan from an
     * unzoned branch still never sees a zone.
     *
     * @throws LoanApprovalException when the loan has no chain at all
     */
    public function firstStage(Loan $loan): LoanApprovalStage
    {
        return $this->router->stagesFor($loan)->first()
            ?? throw LoanApprovalException::noChainConfigured();
    }

    /**
     * The stage a loan should return to once its e-mandate is live.
     *
     * Read off the loan's route so a mandate completed at an unzoned branch
     * cannot deposit the loan back at a zone stage that branch never had.
     */
    public function stageAfterMandate(Loan $loan): ?LoanApprovalStage
    {
        return $this->router->stagesFor($loan)
            ->first(fn (LoanApprovalStage $stage): bool => $stage->requires_mandate_before);
    }

    /**
     * The whole chain this loan is walking — for the approval screen.
     *
     * @return Collection<int, LoanApprovalStage>
     */
    public function chainFor(Loan $loan): Collection
    {
        return $this->router->stagesFor($loan);
    }

    /**
     * Whether `$actor` may decide this loan at this stage.
     *
     * Two rules, and both are refusals rather than UI hiding:
     *
     *   - the actor holds the stage's configured permission;
     *   - the actor did not raise the application (§14 separation of duties).
     *     An approver signing off their own file defeats every stage after it,
     *     which is precisely what a four-tier chain is for.
     */
    public function canDecide(Loan $loan, LoanApprovalStage $stage, User $actor): bool
    {
        $permission = $stage->permission();

        if ($permission === null || ! $actor->hasPermission($permission)) {
            return false;
        }

        return $loan->created_by !== $actor->getKey();
    }

    /**
     * The same question, as an exception — for the write path.
     *
     * @throws LoanApprovalException
     */
    public function assertCanDecide(Loan $loan, LoanApprovalStage $stage, User $actor): void
    {
        $permission = $stage->permission();

        if ($permission === null) {
            throw LoanApprovalException::stageMisconfigured($stage->code, $stage->required_permission);
        }

        if (! $actor->hasPermission($permission)) {
            throw LoanApprovalException::notPermittedAtStage($stage->name, $stage->required_permission);
        }

        if ($loan->created_by === $actor->getKey()) {
            throw LoanApprovalException::selfApproval($stage->name);
        }
    }

    /**
     * The stage a loan must be at for a decision to be taken on it.
     *
     * @throws LoanApprovalException when the loan is not awaiting approval
     */
    public function requireStage(Loan $loan): LoanApprovalStage
    {
        return $this->currentStage($loan)
            ?? throw LoanApprovalException::notAwaitingApproval($loan->status);
    }

    /**
     * Whether this loan still has to complete an e-mandate.
     *
     * False once one is active — a loan returned for modification and
     * resubmitted must not be sent through the OTP flow a second time for a
     * mandate the bank has already granted.
     */
    private function needsMandate(Loan $loan): bool
    {
        if (! $loan->requires_mandate_snapshot) {
            return false;
        }

        return ! $loan->mandates()
            ->where('status', EMandateStatus::Active->value)
            ->exists();
    }
}
