<?php

declare(strict_types=1);

namespace App\Domain\Repayments\Services;

use App\Domain\Loans\Actions\CloseLoanAction;
use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Loans\Services\LoanStateMachine;
use App\Enums\AuditAction;
use App\Models\Loan;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Date;

/**
 * What a settled installment does to the loan's standing.
 *
 * Two §10 transitions live here: arrears → active when the last overdue
 * installment is cleared, and → closed when nothing at all is outstanding.
 * Closing is what makes a fully repaid loan a real outcome rather than a loan
 * that merely happens to have a zero balance.
 *
 * ## Why this is a service and not a private method
 *
 * It used to be private to RecordRepaymentAction, which was correct while cash
 * was the only thing that could settle an installment. Since the client's
 * advance ruling that is no longer true: an installment can now be cleared by
 * ApplyDueAdvancesAction, with no payment involved at all, and that path must
 * reach exactly the same conclusion about the loan.
 *
 * Copying the rule into the second caller would have been the cheap fix and the
 * wrong one — a loan closed by cash and a loan closed by advance would drift
 * apart in freeze date, audit action or arrears handling, and nobody would find
 * out until a customer was refused a top-up they were entitled to.
 */
final class LoanStatusReconciler
{
    public function __construct(
        private readonly LoanStateMachine $states,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Moves the loan on if settling installments changed its standing.
     *
     * Expects a loan with fresh schedules and expects to be called inside the
     * caller's transaction: a loan closed by a rolled-back payment would be a
     * closed loan the borrower still owes money on.
     */
    public function reconcile(Loan $loan, ?User $actor): void
    {
        $stillOverdue = $loan->schedules->contains(
            fn ($s): bool => $s->status->value === 'overdue' && $s->outstandingTotal()->isPositive(),
        );

        if ($loan->status === LoanStatus::Arrears && ! $stillOverdue) {
            $this->states->transition($loan, LoanStatus::Active, $actor, 'Arrears cleared by repayment');
        }

        if ($loan->outstandingTotal()->isPositive() || ! $loan->status->isOpenBook()) {
            return;
        }

        if ($loan->status === LoanStatus::Arrears) {
            $this->states->transition($loan, LoanStatus::Active, $actor, 'Arrears cleared by final repayment');
        }

        $this->states->transition($loan, LoanStatus::Closed, $actor, 'Loan fully repaid');

        $loan->update([
            'closed_at' => Date::now(),
            'frozen_until' => Date::now()->addDays(CloseLoanAction::DEFAULT_FREEZE_DAYS)->toDateString(),
        ]);

        $this->audit->log(
            AuditAction::LoanClosedByRepayment,
            $loan,
            after: ['closed_at' => Date::now()->toIso8601String()],
            actor: $actor,
        );
    }
}
