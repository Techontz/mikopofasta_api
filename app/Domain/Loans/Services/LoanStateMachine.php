<?php

declare(strict_types=1);

namespace App\Domain\Loans\Services;

use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Loans\Exceptions\IllegalLoanTransitionException;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Support\Facades\Date;

/**
 * The §10 loan lifecycle, encoded once.
 *
 * Mirrors the frontend's LOAN_TRANSITIONS table exactly. Every status change
 * goes through `transition()`, which refuses illegal moves and writes the
 * `loan_status_history` row §10 requires ("kila action recorded") — so no
 * action can invent a transition, and none can forget to record one.
 */
final class LoanStateMachine
{
    /**
     * @return array<string, list<LoanStatus>>
     */
    public static function transitions(): array
    {
        return [
            LoanStatus::Draft->value => [LoanStatus::PendingManagerApproval, LoanStatus::Cancelled],
            LoanStatus::PendingManagerApproval->value => [
                LoanStatus::Rejected, LoanStatus::MandatePendingOtp, LoanStatus::PendingCreditReview,
            ],
            LoanStatus::Rejected->value => [],
            LoanStatus::MandatePendingOtp->value => [LoanStatus::MandateActive, LoanStatus::MandateFailed],
            LoanStatus::MandateFailed->value => [LoanStatus::MandatePendingOtp, LoanStatus::Cancelled],
            LoanStatus::MandateActive->value => [LoanStatus::PendingCreditReview],
            LoanStatus::PendingCreditReview->value => [LoanStatus::PendingFinance, LoanStatus::Rejected],
            LoanStatus::PendingFinance->value => [LoanStatus::AwaitingDisbursement],
            LoanStatus::AwaitingDisbursement->value => [LoanStatus::Active, LoanStatus::DisbursementFailed],
            LoanStatus::DisbursementFailed->value => [
                LoanStatus::AwaitingDisbursement, LoanStatus::Escalated, LoanStatus::Cancelled,
            ],
            LoanStatus::Escalated->value => [LoanStatus::AwaitingDisbursement, LoanStatus::Cancelled],
            LoanStatus::Active->value => [
                LoanStatus::Arrears, LoanStatus::Closed, LoanStatus::Defaulted, LoanStatus::Frozen,
            ],
            LoanStatus::Arrears->value => [LoanStatus::Active, LoanStatus::Defaulted, LoanStatus::Closed],
            LoanStatus::Defaulted->value => [LoanStatus::WrittenOff, LoanStatus::Recovered],
            LoanStatus::WrittenOff->value => [LoanStatus::Recovered],
            LoanStatus::Recovered->value => [LoanStatus::Closed],
            LoanStatus::Closed->value => [],
            LoanStatus::Frozen->value => [LoanStatus::Active],
            LoanStatus::Cancelled->value => [],
        ];
    }

    public function canTransition(LoanStatus $from, LoanStatus $to): bool
    {
        return in_array($to, self::transitions()[$from->value] ?? [], true);
    }

    /**
     * Moves the loan and records the move.
     *
     * The caller is expected to already be inside a transaction: the status
     * change and its history row must land together or not at all, or the
     * audit trail would describe a state the loan is not in.
     *
     * @throws IllegalLoanTransitionException
     */
    public function transition(Loan $loan, LoanStatus $to, ?User $actor, ?string $reason = null): Loan
    {
        $from = $loan->status;

        if (! $this->canTransition($from, $to)) {
            throw new IllegalLoanTransitionException($from, $to);
        }

        $loan->update(['status' => $to]);

        $loan->statusHistory()->create([
            'from_status' => $from,
            'to_status' => $to,
            'changed_by' => $actor?->getKey(),
            'reason' => $reason,
            'created_at' => Date::now(),
        ]);

        return $loan;
    }

    /**
     * Records the loan's initial state, which has no predecessor.
     */
    public function recordInitial(Loan $loan, ?User $actor): void
    {
        $loan->statusHistory()->create([
            'from_status' => null,
            'to_status' => $loan->status,
            'changed_by' => $actor?->getKey(),
            'reason' => null,
            'created_at' => Date::now(),
        ]);
    }
}
