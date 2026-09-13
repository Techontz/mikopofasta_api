<?php

declare(strict_types=1);

namespace App\Domain\Loans\Services;

use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Loans\Enums\DisbursementStatus;
use App\Domain\Loans\Exceptions\DisbursementFundingException;
use App\Enums\ActiveStatus;
use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\DisbursementBatch;
use App\Models\Loan;
use App\Support\Money;

/**
 * Which company account a loan is paid out of, and whether it can afford it.
 *
 * The source is one of:
 *
 *   - a registered company Bank or Mobile Money account that may send money
 *     (`bank_accounts`, usage disbursement or both) — its 8xxx ledger account; or
 *   - the loan branch's teller cash (1500-x), when paid over the counter.
 *
 * Chosen when the batch is prepared and stored on it, so settlement credits the
 * account the officer actually chose, not whichever is first on the day.
 */
final class DisbursementFunding
{
    public function __construct(
        private readonly AccountResolver $accounts,
        private readonly LoanFeeCalculator $fees,
    ) {}

    /**
     * @return array{account: ChartOfAccount, bankAccount: BankAccount|null}
     */
    public function choose(Loan $loan, ?int $bankAccountId, bool $fromCash): array
    {
        if ($fromCash) {
            return ['account' => $this->accounts->tellerCash($loan->branch), 'bankAccount' => null];
        }

        $bankAccount = $bankAccountId === null
            ? BankAccount::query()->acceptingOutflow()->whereNotNull('chart_account_id')->orderBy('id')->first()
            : BankAccount::query()->find($bankAccountId);

        if ($bankAccount === null) {
            throw DisbursementFundingException::noFundingAccount();
        }

        $usage = $bankAccount->usage;
        $chart = $bankAccount->chartAccount;

        if (
            $bankAccount->status !== ActiveStatus::Active
            || ! $usage->acceptsOutflow()
            || $chart === null
            || $chart->status !== ActiveStatus::Active
        ) {
            throw DisbursementFundingException::accountCannotSend($bankAccount->account_name);
        }

        return ['account' => $chart, 'bankAccount' => $bankAccount];
    }

    /**
     * The funding account a batch settles against.
     *
     * A batch prepared before funding accounts were recorded has none; it is
     * resolved now, the same way an unspecified choice is at preparation.
     *
     * @return array{account: ChartOfAccount, bankAccount: BankAccount|null}
     */
    public function forBatch(DisbursementBatch $batch): array
    {
        if ($batch->funding_account_id !== null) {
            return [
                'account' => ChartOfAccount::query()->findOrFail($batch->funding_account_id),
                'bankAccount' => $batch->funding_bank_account_id === null
                    ? null
                    : BankAccount::query()->find($batch->funding_bank_account_id),
            ];
        }

        return $this->choose($batch->loan, null, false);
    }

    /** What actually leaves the company: the principal less the fee withheld. */
    public function netPayout(Loan $loan): Money
    {
        return $loan->principal()->subtract($this->fees->totalDeducted($loan));
    }

    /**
     * Refuses a payout the account cannot cover.
     *
     * "Available" is the ledger balance less what other batches still in flight
     * from the same account will take — so two loans prepared against one
     * balance cannot both be promised the same money.
     */
    public function assertCanCover(ChartOfAccount $account, Loan $loan, ?int $exceptBatchId = null): void
    {
        $required = $this->netPayout($loan);

        $committed = Money::sum(
            DisbursementBatch::query()
                ->with('loan')
                ->where('funding_account_id', $account->getKey())
                ->where('status', DisbursementStatus::Pending)
                ->when($exceptBatchId !== null, fn ($q) => $q->whereKeyNot($exceptBatchId))
                ->where('loan_id', '!=', $loan->getKey())
                ->get()
                ->map(fn (DisbursementBatch $b): Money => $this->netPayout($b->loan)),
        );

        $available = $account->load('balances')->cachedBalance()->subtract($committed);

        if ($available->lessThan($required)) {
            throw DisbursementFundingException::insufficientFunds(
                $account->name,
                $available->toDecimalString(),
                $required->toDecimalString(),
            );
        }
    }
}
