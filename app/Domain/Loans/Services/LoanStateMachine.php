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

            /*
             * The approval chain — Branch Manager → Zone Manager → Head Office
             * Credit. Every stage offers the same four exits the client
             * specified: forward (approve), Rejected, ReturnedForModification
             * and OnHold. They are listed per stage rather than generated,
             * because this table is also what the frontend mirrors and a
             * generated one could not be read.
             *
             * The two paths OUT of the last stage before credit — mandate first
             * or straight through — are §10's conditional branch, decided by
             * the product snapshot in LoanApprovalWorkflow.
             */
            LoanStatus::PendingManagerApproval->value => [
                LoanStatus::PendingZoneApproval,
                LoanStatus::MandatePendingOtp, LoanStatus::PendingCreditReview,
                LoanStatus::Rejected, LoanStatus::ReturnedForModification, LoanStatus::OnHold,
            ],
            LoanStatus::PendingZoneApproval->value => [
                LoanStatus::MandatePendingOtp, LoanStatus::PendingCreditReview,
                LoanStatus::Rejected, LoanStatus::ReturnedForModification, LoanStatus::OnHold,
            ],

            /*
             * A returned application goes back to the FIRST stage, not to the
             * one that returned it. The officer has changed something, so every
             * approver who already cleared it cleared a different application.
             */
            LoanStatus::ReturnedForModification->value => [
                LoanStatus::PendingManagerApproval, LoanStatus::Cancelled,
            ],

            /*
             * A hold resumes to whatever stage it paused — `hold_resume_status`
             * decides which, and the state machine permits all three so that
             * releasing never has to bypass this table.
             */
            LoanStatus::OnHold->value => [
                LoanStatus::PendingManagerApproval, LoanStatus::PendingZoneApproval,
                LoanStatus::PendingCreditReview, LoanStatus::Cancelled,
            ],

            LoanStatus::Rejected->value => [],
            LoanStatus::MandatePendingOtp->value => [LoanStatus::MandateActive, LoanStatus::MandateFailed],
            LoanStatus::MandateFailed->value => [LoanStatus::MandatePendingOtp, LoanStatus::Cancelled],
            LoanStatus::MandateActive->value => [LoanStatus::PendingCreditReview],
            LoanStatus::PendingCreditReview->value => [
                LoanStatus::PendingFinance,
                LoanStatus::Rejected, LoanStatus::ReturnedForModification, LoanStatus::OnHold,
            ],
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

    /**
     * The moves only an approved reversal may make — §5's undo, not §10's
     * lifecycle.
     *
     * Kept OUT of `transitions()` on purpose. That table mirrors the
     * frontend's LOAN_TRANSITIONS and describes how a loan moves forward under
     * its own steam; every entry below runs a loan BACKWARDS, and none of them
     * may be reachable from ordinary code. Folding them in would make `closed`
     * a status any action could walk out of, which is exactly the property
     * that makes `closed` mean something.
     *
     * Reaching them requires `reverseTransition()`, which only the reversal
     * executors call and which records the move like any other.
     *
     * @return array<string, list<LoanStatus>>
     */
    public static function reversalTransitions(): array
    {
        return [
            /*
             * Reversing the payment that closed a loan reopens it. Which of
             * the two it reopens INTO is not this table's decision —
             * LoanStandingRecalculator reads the schedule and picks arrears if
             * an installment is past due, active otherwise.
             */
            LoanStatus::Closed->value => [LoanStatus::Active, LoanStatus::Arrears],

            // The same, for a loan an early settlement had frozen.
            LoanStatus::Frozen->value => [LoanStatus::Active, LoanStatus::Arrears],

            /*
             * Reversing a disbursement. The loan lands in `disbursement_failed`
             * — the position it is actually in once the payout has been taken
             * back — from which the ordinary retry, escalate and cancel paths
             * are already open. Sending it to `awaiting_disbursement` instead
             * would be closer to the truth and useless: nothing prepares a
             * batch from there, so the loan would have no way out.
             *
             * Only permitted while nothing has been repaid. That guard lives
             * in the executor, because it is a fact about the loan's payments,
             * not about its status.
             */
            LoanStatus::Active->value => [LoanStatus::DisbursementFailed, LoanStatus::Arrears],
            LoanStatus::Arrears->value => [LoanStatus::DisbursementFailed, LoanStatus::Active],
        ];
    }

    public function canTransition(LoanStatus $from, LoanStatus $to): bool
    {
        return in_array($to, self::transitions()[$from->value] ?? [], true);
    }

    /**
     * Moves a loan backwards under an approved reversal.
     *
     * Writes the same `loan_status_history` row a forward move does, with the
     * reversal's reason on it, so the loan's history shows the undo rather
     * than a status that silently changed.
     *
     * @throws IllegalLoanTransitionException
     */
    public function reverseTransition(Loan $loan, LoanStatus $to, ?User $actor, string $reason): Loan
    {
        $from = $loan->status;

        if ($from === $to) {
            return $loan;
        }

        $permitted = in_array($to, self::reversalTransitions()[$from->value] ?? [], true)
            || $this->canTransition($from, $to);

        if (! $permitted) {
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
