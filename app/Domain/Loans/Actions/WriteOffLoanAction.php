<?php

declare(strict_types=1);

namespace App\Domain\Loans\Actions;

use App\Domain\Ledger\DTOs\JournalLine;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Loans\Exceptions\WriteOffException;
use App\Domain\Loans\Services\LoanStateMachine;
use App\Enums\AuditAction;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Models\User;
use App\Models\WriteOff;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Takes an uncollectable loan off the book — §5's Write-Off account.
 *
 * The posting §5 defines, and only this:
 *
 *     Dr  4200 Write-Off Expense    (the loss the business is accepting)
 *       Cr  1200 Loan Receivable    (the asset that was never going to arrive)
 *
 * ## Why only principal reaches the ledger
 *
 * A borrower who defaults owes principal, accrued interest and accrued penalty.
 * Only the principal is written off here.
 *
 * The reason is that this system recognises interest and penalty income on
 * COLLECTION, not on accrual — that is the reading OSC-1 settled, and it is why
 * the overdue job posts nothing. Interest that was never collected was never
 * recognised as income, so there is no revenue to reverse. Writing it off would
 * debit an expense against income the books never carried, overstating both the
 * loss and the original earnings.
 *
 * The forgone interest and penalty are still recorded on the row. The recovery
 * officer negotiating a settlement needs to know what the borrower actually
 * owed, and the arrears report needs it to explain the gap between the book and
 * the debt.
 *
 * ## Why the schedules are left alone
 *
 * They are not zeroed. The schedule is the record of what was agreed, and a
 * write-off is a decision about collectability, not a renegotiation. Zeroing it
 * would erase the evidence of what the borrower was actually asked to pay, and
 * a recovery arriving later would have nothing to reconcile against.
 */
final class WriteOffLoanAction
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AccountResolver $accounts,
        private readonly LoanStateMachine $states,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Loan $loan, string $reason, User $actor): WriteOff
    {
        if ($loan->status !== LoanStatus::Defaulted) {
            throw WriteOffException::notEligible($loan->loan_number, $loan->status);
        }

        if (WriteOff::query()->where('loan_id', $loan->getKey())->exists()) {
            throw WriteOffException::alreadyWrittenOff($loan->loan_number);
        }

        $loan->loadMissing('schedules');

        $principal = Money::sum(
            $loan->schedules->map(fn (LoanSchedule $s): Money => $s->outstandingPrincipal()),
        );
        $interest = Money::sum(
            $loan->schedules->map(fn (LoanSchedule $s): Money => $s->outstandingInterest()),
        );
        $penalty = Money::sum(
            $loan->schedules->map(fn (LoanSchedule $s): Money => $s->outstandingPenalty()),
        );

        return DB::transaction(function () use ($loan, $reason, $actor, $principal, $interest, $penalty): WriteOff {
            /*
             * A loan can default with its principal fully repaid and only
             * interest outstanding. There is nothing to write off in that case
             * and LedgerService would reject a zero-value entry, so the row is
             * created without one — the decision is still recorded, and the
             * ledger correctly shows no loss.
             */
            $entry = $principal->isPositive()
                ? $this->ledger->post(
                    sprintf('Write-off %s', $loan->loan_number),
                    JournalSourceType::WriteOff,
                    (int) $loan->getKey(),
                    [
                        JournalLine::debit(
                            $this->accounts->systemId(SystemAccountCode::WriteOff),
                            $principal,
                            $loan->branch_id,
                            $loan->customer_id,
                            (int) $loan->getKey(),
                        ),
                        JournalLine::credit(
                            $this->accounts->systemId(SystemAccountCode::LoanReceivable),
                            $principal,
                            $loan->branch_id,
                            $loan->customer_id,
                            (int) $loan->getKey(),
                        ),
                    ],
                    $actor,
                )
                : null;

            $writeOff = WriteOff::query()->create([
                'loan_id' => $loan->getKey(),
                'principal_written_off' => $principal->toDecimalString(),
                'interest_forgone' => $interest->toDecimalString(),
                'penalty_forgone' => $penalty->toDecimalString(),
                'reason' => $reason,
                'approved_by' => $actor->getKey(),
                'journal_entry_id' => $entry?->getKey(),
            ]);

            $this->states->transition($loan, LoanStatus::WrittenOff, $actor, $reason);

            $this->audit->log(
                AuditAction::LoanWrittenOff,
                $loan,
                before: ['status' => LoanStatus::Defaulted->value],
                after: [
                    'status' => LoanStatus::WrittenOff->value,
                    'principal_written_off' => $writeOff->principal_written_off,
                    'interest_forgone' => $writeOff->interest_forgone,
                    'penalty_forgone' => $writeOff->penalty_forgone,
                    'reason' => $reason,
                    'journal_entry_id' => $entry?->getKey(),
                ],
                actor: $actor,
            );

            return $writeOff->load('loan');
        });
    }
}
