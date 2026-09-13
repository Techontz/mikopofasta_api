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
use App\Domain\Loans\Services\DisbursementFunding;
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
 * §6: "No ledger entry exists until a disbursement batch reaches success." The
 * entry records the money actually leaving the company:
 *
 *   Dr  1200 Loan Receivable        principal     (the customer now owes it)
 *     Cr  funding Bank/Cash account principal − fee (what was paid out)
 *     Cr  2100 Fee Income           fee           (withheld from the payout)
 *
 * Every line carries the loan, customer and branch, so the customer's and the
 * loan's ledgers show the disbursement and the funding account's ledger shows
 * the money leaving for that loan.
 *
 * ## Why no longer Cr Principal
 *
 * Until now the credit went to 1100 Principal, an equity account, and no
 * Bank/Cash account was ever credited. The loan book therefore grew while the
 * cash that paid for it stayed on the books too — the same money counted
 * twice, once as cash and once as a receivable, with equity inflated to
 * balance it. Crediting the account the money left fixes that. Principal keeps
 * its other meaning — reinvested profit from the month-end close — untouched.
 * Entries already posted are not rewritten; the ledger is immutable.
 *
 * ## Atomic and once only
 *
 * The loan and the batch are locked, re-checked, posted, linked and activated
 * in ONE transaction. If the posting fails nothing commits: the batch stays
 * pending and the loan is not active. A repeated or concurrent callback finds
 * the batch no longer pending and is refused; and `settled_loan_id` is UNIQUE,
 * so the database itself refuses a second successful batch for the same loan.
 *
 * A failure callback posts nothing: no money moved.
 */
final class SettleDisbursementAction
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AccountResolver $accounts,
        private readonly LoanStateMachine $states,
        private readonly LoanFeeCalculator $fees,
        private readonly AuditLogger $audit,
        private readonly DisbursementFunding $funding,
    ) {}

    public function succeed(DisbursementBatch $batch, User $actor): Loan
    {
        $this->guard($batch->loan, $batch);

        return DB::transaction(function () use ($batch, $actor): Loan {
            [$loan, $batch] = $this->lockAndRecheck($batch);

            $principal = $loan->principal();

            /*
             * The loan fee, withheld from the payout. The borrower owes the full
             * principal either way — the fee is deducted from what they receive,
             * not from what they owe — so Loan Receivable is debited in full and
             * the funding account is credited only with what actually left.
             */
            $fee = $this->fees->totalDeducted($loan);
            $netPayout = $principal->subtract($fee);

            ['account' => $fundingAccount] = $this->funding->forBatch($batch);

            $lines = [
                JournalLine::debit(
                    $this->accounts->systemId(SystemAccountCode::LoanReceivable),
                    $principal,
                    $loan->branch_id,
                    $loan->customer_id,
                    (int) $loan->getKey(),
                ),
            ];

            // A loan whose fee swallows the whole principal pays nothing out,
            // and LedgerService rejects a zero-amount line.
            if ($netPayout->isPositive()) {
                $lines[] = JournalLine::credit(
                    (int) $fundingAccount->getKey(),
                    $netPayout,
                    $loan->branch_id,
                    $loan->customer_id,
                    (int) $loan->getKey(),
                );
            }

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
                description: sprintf('Disbursement of %s — %s', $loan->loan_number, $batch->batch_reference),
                sourceType: JournalSourceType::LoanDisbursement,
                sourceId: (int) $loan->getKey(),
                lines: $lines,
                postedBy: $actor,
            );

            $batch->update([
                'status' => DisbursementStatus::Success,
                'completed_at' => Date::now(),
                'funding_account_id' => $fundingAccount->getKey(),
                'journal_entry_id' => $entry->getKey(),
                // UNIQUE — the database's own refusal of a second success.
                'settled_loan_id' => $loan->getKey(),
            ]);

            // Only now, with the entry written, does the loan go live.
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
                    'funding_account' => $fundingAccount->code,
                    'principal' => $principal->toDecimalString(),
                    'fee_charged' => $fee->toDecimalString(),
                    'net_disbursed' => $netPayout->toDecimalString(),
                ],
                actor: $actor,
            );

            Log::channel('operations')->info('Disbursement settled and loan activated', [
                'loan_number' => $loan->loan_number,
                'batch_reference' => $batch->batch_reference,
                'principal' => $principal->toDecimalString(),
                'funding_account' => $fundingAccount->code,
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

        return DB::transaction(function () use ($batch, $reason, $actor): Loan {
            [$loan, $batch] = $this->lockAndRecheck($batch);

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

    /**
     * Locks the loan and the batch and checks them again.
     *
     * The guard outside the transaction reads rows another request may be
     * about to change. Two callbacks for the same batch arriving together
     * would both pass it; under these locks the second waits, then sees the
     * batch already settled and is refused.
     *
     * @return array{0: Loan, 1: DisbursementBatch}
     */
    private function lockAndRecheck(DisbursementBatch $batch): array
    {
        $loan = Loan::query()->lockForUpdate()->findOrFail($batch->loan_id);
        $locked = DisbursementBatch::query()->lockForUpdate()->findOrFail($batch->getKey());

        $this->guard($loan, $locked);

        // The caller's instance is the one the controller re-reads.
        $batch->setRawAttributes($locked->getAttributes(), true);

        return [$loan, $batch];
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
