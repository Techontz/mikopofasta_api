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
use App\Models\BankAccount;
use App\Models\Loan;
use App\Models\Recovery;
use App\Models\User;
use App\Models\WriteOff;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Money arriving on a loan that was already written off — §5's Recovered Loans.
 *
 * The posting §5 defines:
 *
 *     Dr  cash or bank             (what actually arrived)
 *       Cr  4300 Recovered Loans   (income, because the loss was already taken)
 *
 * ## Why this is not a repayment
 *
 * The receivable is gone — WriteOffLoanAction credited it away. Routing this
 * through the ordinary repayment path would credit Loan Receivable a second
 * time and drive the account negative, and it would allocate against schedules
 * whose balances no longer represent anything the books carry.
 *
 * Recovered Loans is an income account rather than a reversal of the write-off
 * expense, which keeps both facts visible: the period that took the loss shows
 * the loss, and the period that recovered shows the recovery. Netting them
 * would make a bad year and a good year look like an uneventful one.
 *
 * ## Why this matters beyond accounting
 *
 * The client's commission rule turns on it — "mikopo iliyodefault ikirudishwa
 * kutakuwa na commission kubwa zaidi", recovered defaults earn a higher
 * commission. That rule needs recoveries to be separable from ordinary
 * collections in the ledger itself, not merely tagged in a report.
 *
 * ## Partial recovery
 *
 * A written-off loan may be recovered in instalments over a long period, so
 * many recoveries may point at one write-off. The loan moves to `recovered` on
 * the first one: the status records that recovery has begun, and `WriteOff::
 * outstanding()` is what says how much is still being chased.
 */
final class RecordRecoveryAction
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AccountResolver $accounts,
        private readonly LoanStateMachine $states,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(
        Loan $loan,
        Money $amount,
        ?int $bankAccountId,
        ?string $narrative,
        User $actor,
    ): Recovery {
        $writeOff = WriteOff::query()->where('loan_id', $loan->getKey())->first();

        if ($writeOff === null) {
            throw WriteOffException::notWrittenOff($loan->loan_number);
        }

        return DB::transaction(function () use ($loan, $writeOff, $amount, $bankAccountId, $narrative, $actor): Recovery {
            $debitAccount = $this->debitAccountFor($bankAccountId);

            $entry = $this->ledger->post(
                sprintf('Recovery on written-off loan %s', $loan->loan_number),
                JournalSourceType::Recovery,
                (int) $loan->getKey(),
                [
                    JournalLine::debit(
                        (int) $debitAccount,
                        $amount,
                        $loan->branch_id,
                        $loan->customer_id,
                        (int) $loan->getKey(),
                    ),
                    JournalLine::credit(
                        $this->accounts->systemId(SystemAccountCode::RecoveredLoans),
                        $amount,
                        $loan->branch_id,
                        $loan->customer_id,
                        (int) $loan->getKey(),
                    ),
                ],
                $actor,
            );

            $recovery = Recovery::query()->create([
                'loan_id' => $loan->getKey(),
                'write_off_id' => $writeOff->getKey(),
                'amount' => $amount->toDecimalString(),
                'bank_account_id' => $bankAccountId,
                'narrative' => $narrative,
                'recorded_by' => $actor->getKey(),
                'journal_entry_id' => $entry->getKey(),
            ]);

            // Only on the first recovery: `written_off → recovered` is a legal
            // transition, `recovered → recovered` is not, and a second
            // instalment must not be refused because of it.
            if ($loan->status === LoanStatus::WrittenOff) {
                $this->states->transition($loan, LoanStatus::Recovered, $actor, 'Recovery received');
            }

            $this->audit->log(
                AuditAction::LoanRecoveryRecorded,
                $loan,
                after: [
                    'amount' => $recovery->amount,
                    'write_off_id' => $writeOff->getKey(),
                    'recovered_to_date' => $writeOff->fresh()?->recoveredTotal()->toDecimalString(),
                    'journal_entry_id' => $entry->getKey(),
                ],
                actor: $actor,
            );

            return $recovery->load(['loan', 'writeOff']);
        });
    }

    /**
     * Where the recovered money landed.
     *
     * The caller names a bank account when the settlement was banked; otherwise
     * the default bank account applies, the same resolution an unattributed
     * provider payment uses.
     */
    private function debitAccountFor(?int $bankAccountId): int
    {
        if ($bankAccountId !== null) {
            $bankAccount = BankAccount::query()->findOrFail($bankAccountId);

            return (int) $bankAccount->chart_account_id;
        }

        return (int) $this->accounts->defaultBankAccount()->getKey();
    }
}
