<?php

declare(strict_types=1);

namespace App\Domain\Loans\Actions;

use App\Domain\Ledger\DTOs\JournalLine;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Loans\Enums\DisbursementStatus;
use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Loans\Exceptions\LoanStateException;
use App\Domain\Loans\Services\LoanFeeCalculator;
use App\Domain\Loans\Services\LoanStateMachine;
use App\Enums\AuditAction;
use App\Models\DisbursementBatch;
use App\Models\Loan;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Settles a disbursement batch — the provider callback from §15.2
 * (`POST /webhooks/vodacom/disbursement-status`).
 *
 * This is the piece Phase 5 deliberately left out, because it cannot exist
 * without the ledger. §6: "No ledger entry exists until a disbursement batch
 * reaches success", and §5's canonical posting for that moment is:
 *
 *   Dr Loan Receivable          (the customer now owes us)
 *     Cr Principal Account      (capital deployed into the book)
 *
 * The ORDER here is the point. The ledger is posted FIRST and the loan is
 * activated second, inside one transaction. If the posting fails the loan
 * never becomes active — which is exactly the invariant §6 states and the one
 * that would otherwise be violated by an `active` loan with no entry behind it.
 *
 * A failure callback does not post anything: no money moved, so there is
 * nothing to record beyond the batch's own status.
 */
final class SettleDisbursementAction
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AccountResolver $accounts,
        private readonly LoanStateMachine $states,
        private readonly LoanFeeCalculator $fees,
        private readonly AuditLogger $audit,
    ) {}

    public function succeed(DisbursementBatch $batch, User $actor): Loan
    {
        $loan = $batch->loan;

        $this->guard($loan, $batch);

        return DB::transaction(function () use ($loan, $batch, $actor): Loan {
            $principal = $loan->principal();

            /*
             * The loan fee, withheld from the payout — Loan Fee → Deducted
             * Income, and the fourth of the steps docs/modules/loan-charges.md
             * named as needed to wire `loan_fees` in.
             *
             * The borrower owes the full principal either way: the fee is
             * deducted from what they receive, not from what they owe. So Loan
             * Receivable is still debited in full, and the credit splits.
             *
             * §5: Dr Loan Receivable · Cr Principal Account, plus, when a fee
             * was agreed:
             *
             *   Dr Loan Receivable      principal
             *     Cr Principal Account  principal − fee
             *     Cr 2100 Fee Income    fee
             *
             * Principal Account is credited only with what actually left as
             * capital, which keeps its meaning — a running measure of capital
             * deployed into the loan book — true. Crediting it in full and
             * debiting the fee back would report capital that never left.
             */
            $fee = $this->fees->totalDeducted($loan);
            $lines = [
                JournalLine::debit(
                    $this->accounts->systemId(SystemAccountCode::LoanReceivable),
                    $principal,
                    $loan->branch_id,
                    $loan->customer_id,
                    (int) $loan->getKey(),
                ),
                JournalLine::credit(
                    $this->accounts->systemId(SystemAccountCode::Principal),
                    $principal->subtract($fee),
                    $loan->branch_id,
                    $loan->customer_id,
                    (int) $loan->getKey(),
                ),
            ];

            // Only when there is one: LedgerService rejects a zero-amount line,
            // and a loan on a product with no fee configured has none.
            if ($fee->isPositive()) {
                $lines[] = JournalLine::credit(
                    $this->accounts->systemId(SystemAccountCode::FeeIncome),
                    $fee,
                    $loan->branch_id,
                    $loan->customer_id,
                    (int) $loan->getKey(),
                );
            }

            $entry = $this->ledger->post(
                description: sprintf('Disbursement of %s', $loan->loan_number),
                sourceType: JournalSourceType::LoanDisbursement,
                sourceId: (int) $loan->getKey(),
                lines: $lines,
                postedBy: $actor,
            );

            $batch->update([
                'status' => DisbursementStatus::Success,
                'completed_at' => Date::now(),
            ]);

            // Only now, with the entry committed, does the loan go live.
            $this->states->transition($loan, LoanStatus::Active, $actor, 'Disbursement confirmed by provider');

            /*
             * The clock starts at disbursement, not at application: the
             * frontend sets expectedCompletionDate to disbursement date +
             * tenure at this same moment, and the arrears reports read it.
             */
            $loan->update([
                'disbursement_date' => Date::now()->toDateString(),
                'expected_completion_date' => Date::now()->addDays($loan->tenure_days)->toDateString(),

                /*
                 * What was actually withheld, in shillings. Recorded rather
                 * than recomputed later: this is the figure the entry above
                 * posted, and the Deducted Income screen must show the same
                 * number the Fee Income account holds.
                 */
                'fee_charged' => $fee->toDecimalString(),
            ]);

            $this->audit->log(
                AuditAction::LoanDisbursed,
                $loan,
                after: [
                    'batch_reference' => $batch->batch_reference,
                    'journal_entry' => $entry->entry_number,
                    'principal' => $principal->toDecimalString(),
                    'fee_charged' => $fee->toDecimalString(),
                    'net_disbursed' => $principal->subtract($fee)->toDecimalString(),
                ],
                actor: $actor,
            );

            Log::channel('operations')->info('Disbursement settled and loan activated', [
                'loan_number' => $loan->loan_number,
                'batch_reference' => $batch->batch_reference,
                'principal' => $principal->toDecimalString(),
                'journal_entry' => $entry->entry_number,
            ]);

            return $loan->fresh(['customer', 'product', 'schedules']);
        });
    }

    /**
     * A failed callback. Nothing is posted — no money moved.
     */
    public function fail(DisbursementBatch $batch, string $reason, User $actor): Loan
    {
        $loan = $batch->loan;

        $this->guard($loan, $batch);

        return DB::transaction(function () use ($loan, $batch, $reason, $actor): Loan {
            $batch->update([
                'status' => DisbursementStatus::Failed,
                'failure_reason' => $reason,
                'completed_at' => Date::now(),
            ]);

            Log::channel('operations')->warning('Disbursement failed', [
                'loan_number' => $loan->loan_number,
                'batch_reference' => $batch->batch_reference,
                'reason' => $reason,
            ]);

            $this->states->transition($loan, LoanStatus::DisbursementFailed, $actor, $reason);

            return $loan->fresh();
        });
    }

    private function guard(Loan $loan, DisbursementBatch $batch): void
    {
        if ($loan->status !== LoanStatus::AwaitingDisbursement) {
            throw LoanStateException::notAwaitingDisbursement();
        }

        // A callback that arrives twice must not post twice — the batch's own
        // status is the idempotency marker.
        if ($batch->status !== DisbursementStatus::Pending) {
            throw LoanStateException::disbursementAlreadySettled($batch->batch_reference);
        }
    }
}
