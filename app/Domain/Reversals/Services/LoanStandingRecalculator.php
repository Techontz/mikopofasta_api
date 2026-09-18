<?php

declare(strict_types=1);

namespace App\Domain\Reversals\Services;

use App\Domain\Loans\Actions\CloseLoanAction;
use App\Domain\Loans\Enums\LoanScheduleStatus;
use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Loans\Services\LoanStateMachine;
use App\Enums\AuditAction;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Date;

/**
 * Where a loan stands after money has been taken back off it.
 *
 * LoanStatusReconciler is the forward half of this: it moves a loan on when a
 * payment settles something. It cannot be reused here, because everything it
 * knows how to do runs one way — it closes loans and clears arrears, and a
 * reversal needs to reopen and re-impose them.
 *
 * The rule is the same in both directions and is read off the schedule rather
 * than remembered:
 *
 *   nothing outstanding            → closed
 *   something outstanding, overdue → arrears
 *   something outstanding          → active
 *
 * Deriving it, instead of recording what the loan was before the payment, is
 * deliberate. A loan may have been paid, penalised and partly reversed since;
 * the schedule is what is true now, and a remembered status would be a guess
 * about a loan that has moved on.
 */
final class LoanStandingRecalculator
{
    public function __construct(
        private readonly LoanStateMachine $states,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Expects fresh schedules and expects to run inside the caller's
     * transaction.
     */
    public function recalculate(Loan $loan, ?User $actor, string $reason): void
    {
        /*
         * A loan that was written off, defaulted or cancelled is not brought
         * back by a reversal. Those are decisions somebody made about the
         * borrower, not consequences of a payment, and undoing them is the
         * recovery workflow's job.
         */
        if (in_array($loan->status, [
            LoanStatus::WrittenOff, LoanStatus::Recovered, LoanStatus::Defaulted,
            LoanStatus::Cancelled, LoanStatus::Rejected,
        ], true)) {
            return;
        }

        $loan->loadMissing('schedules');

        $target = $this->targetFor($loan);

        if ($target === null || $target === $loan->status) {
            return;
        }

        $reopening = ! $loan->status->isOpenBook() || $loan->status === LoanStatus::Frozen;

        $this->states->reverseTransition($loan, $target, $actor, $reason);

        if ($target === LoanStatus::Closed) {
            /*
             * Waiving the last outstanding penalty can settle a loan. It closes
             * on the same terms a final repayment closes it — including the
             * freeze — because the borrower owes nothing either way.
             */
            $loan->update([
                'closed_at' => Date::now(),
                'frozen_until' => Date::now()->addDays(CloseLoanAction::DEFAULT_FREEZE_DAYS)->toDateString(),
            ]);

            return;
        }

        if ($reopening) {
            /*
             * The loan is live again, so the marks that said otherwise have to
             * go with it. A reopened loan still carrying `closed_at` would be
             * counted as closed by every report that asks the cheap question.
             */
            $loan->update(['closed_at' => null, 'frozen_until' => null]);

            $this->audit->log(
                AuditAction::LoanReopenedByReversal,
                $loan,
                after: ['status' => $target->value, 'reason' => $reason],
                actor: $actor,
            );
        }
    }

    /**
     * The status the schedule says this loan is in, or null when the schedule
     * has nothing to say about it.
     */
    private function targetFor(Loan $loan): ?LoanStatus
    {
        if ($loan->outstandingTotal()->isZero()) {
            // Already closed, or not a loan the schedule can close (still
            // awaiting disbursement, say).
            return $loan->status->isOpenBook() ? LoanStatus::Closed : null;
        }

        $today = Date::now()->startOfDay();

        $overdue = $loan->schedules->contains(
            fn (LoanSchedule $s): bool => $s->status !== LoanScheduleStatus::Cancelled
                && $s->outstandingTotal()->isPositive()
                && $s->due_date->isBefore($today),
        );

        return $overdue ? LoanStatus::Arrears : LoanStatus::Active;
    }
}
