<?php

declare(strict_types=1);

namespace App\Domain\Loans\Services;

use App\Domain\Loans\Enums\EMandateStatus;
use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Loans\Exceptions\LoanApprovalException;
use App\Models\Loan;
use App\Models\LoanApprovalStage;
use App\Models\User;

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
    /** The stage this loan is waiting on, or null if it is not in the chain. */
    public function currentStage(Loan $loan): ?LoanApprovalStage
    {
        return LoanApprovalStage::forStatus($loan->status);
    }

    /**
     * The stage after `$stage`, skipping anything switched off.
     */
    public function nextStage(LoanApprovalStage $stage): ?LoanApprovalStage
    {
        return LoanApprovalStage::query()
            ->where('is_active', true)
            ->where('sequence', '>', $stage->sequence)
            ->orderBy('sequence')
            ->first();
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
        $next = $this->nextStage($stage);

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
     * resubmitted — the first live one.
     *
     * @throws LoanApprovalException when no stage is active at all
     */
    public function firstStage(): LoanApprovalStage
    {
        return LoanApprovalStage::query()
            ->where('is_active', true)
            ->orderBy('sequence')
            ->first()
            ?? throw LoanApprovalException::noChainConfigured();
    }

    /**
     * The stage a loan should return to once its e-mandate is live.
     *
     * Asked rather than hardcoded so that deactivating the credit stage does
     * not strand a mandate-bearing loan in a status nothing will pick up.
     */
    public function stageAfterMandate(): ?LoanApprovalStage
    {
        return LoanApprovalStage::query()
            ->where('is_active', true)
            ->where('requires_mandate_before', true)
            ->orderBy('sequence')
            ->first();
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
