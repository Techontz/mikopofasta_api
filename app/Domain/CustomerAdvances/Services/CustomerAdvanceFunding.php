<?php

declare(strict_types=1);

namespace App\Domain\CustomerAdvances\Services;

use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Loans\Exceptions\DisbursementFundingException;
use App\Enums\ActiveStatus;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ChartOfAccount;

/**
 * Which operational account a salary advance is paid out of.
 *
 * This is the "Principal Operation" of the client's rule made concrete: a
 * registered company Bank or Mobile Money account that may send money, or the
 * branch's own till when the advance is handed over the counter. Recorded on
 * the advance at disbursement, so a collection returns the capital to the
 * account it left rather than to whichever is first on the day.
 *
 * Deliberately the same rules as DisbursementFunding rather than a call into
 * it: that class resolves and guards against a Loan and a DisbursementBatch,
 * neither of which an advance has. What is shared is the policy — only an
 * active account whose usage accepts outflow — and it is restated here rather
 * than loosened.
 */
final class CustomerAdvanceFunding
{
    public function __construct(private readonly AccountResolver $accounts) {}

    /**
     * @return array{account: ChartOfAccount, bankAccount: BankAccount|null}
     */
    public function choose(?Branch $branch, ?int $bankAccountId, bool $fromCash): array
    {
        if ($fromCash) {
            if ($branch === null) {
                throw DisbursementFundingException::noFundingAccount();
            }

            return ['account' => $this->accounts->tellerCash($branch), 'bankAccount' => null];
        }

        $bankAccount = $bankAccountId === null
            ? BankAccount::query()->acceptingOutflow()->whereNotNull('chart_account_id')->orderBy('id')->first()
            : BankAccount::query()->find($bankAccountId);

        if ($bankAccount === null) {
            throw DisbursementFundingException::noFundingAccount();
        }

        $chart = $bankAccount->chartAccount;

        if (
            $bankAccount->status !== ActiveStatus::Active
            || ! $bankAccount->usage->acceptsOutflow()
            || $chart === null
            || $chart->status !== ActiveStatus::Active
        ) {
            throw DisbursementFundingException::accountCannotSend($bankAccount->account_name);
        }

        return ['account' => $chart, 'bankAccount' => $bankAccount];
    }

    /**
     * Where a collection lands.
     *
     * The account the advance was funded from, so the capital returns to the
     * operational money it came out of — which is the whole of the client's
     * "sehemu ya mtaji itarudi Principal Operation". It falls back to the
     * channel's default only for an advance disbursed before this column
     * existed, or one whose funding account has since been retired.
     */
    public function forCollection(ChartOfAccount $fundingAccount, bool $isCashChannel, ?Branch $branch): ChartOfAccount
    {
        if ($fundingAccount->status === ActiveStatus::Active) {
            return $fundingAccount;
        }

        return $this->accounts->cashAccountFor($isCashChannel, $branch);
    }
}
