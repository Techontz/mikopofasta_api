<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Services;

use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Enums\ActiveStatus;
use App\Models\AccountBalance;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Support\Money;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Finds the chart accounts a posting needs, and keeps the balance cache true.
 *
 * §5 requires the engine to resolve "the correct chart_of_accounts row
 * (including auto-resolving branch-scoped or dynamic accounts)". Every posting
 * path asks this class rather than hardcoding an id, so a renamed or re-coded
 * account changes in one place.
 */
final class AccountResolver
{
    /** @var array<string, int> */
    private array $systemCache = [];

    /**
     * A fixed §5 account, by code.
     */
    public function system(SystemAccountCode $code): ChartOfAccount
    {
        $account = ChartOfAccount::query()->where('code', $code->value)->first();

        if ($account === null) {
            throw new RuntimeException(
                "System account [{$code->value}] is missing. Run ChartOfAccountSeeder.",
            );
        }

        return $account;
    }

    public function systemId(SystemAccountCode $code): int
    {
        return $this->systemCache[$code->value] ??= (int) $this->system($code)->getKey();
    }

    /**
     * The teller cash account for a branch, created on demand.
     *
     * §12: "Every branch gets an auto-created Teller Cash chart-of-accounts row
     * at creation — this is what lets Branch P&L and Branch Ledger reports work
     * as simple filtered queries." Resolving lazily here means a branch created
     * before this module existed still gets one the first time cash moves.
     */
    public function tellerCash(Branch $branch): ChartOfAccount
    {
        return ChartOfAccount::query()->firstOrCreate(
            ['code' => '1500-'.$branch->getKey()],
            [
                'name' => $branch->name.' Teller Cash',
                'type' => AccountType::Asset,
                'is_system' => false,
                'branch_id' => $branch->getKey(),
                'status' => ActiveStatus::Active,
            ],
        );
    }

    /**
     * Where money for a given channel lands.
     *
     * Mirrors the frontend's `cashAccountFor`: cash goes to the branch till,
     * everything else to a bank account.
     */
    public function cashAccountFor(bool $isCashChannel, ?Branch $branch): ChartOfAccount
    {
        if ($isCashChannel && $branch !== null) {
            return $this->tellerCash($branch);
        }

        return $this->defaultBankAccount();
    }

    /**
     * The bank account non-cash money lands in.
     *
     * The specification does not describe how a provider payment is routed to
     * one of several bank accounts, so the first active one is used rather
     * than inventing a routing rule. When that decision is made it belongs
     * here, in one place.
     */
    public function defaultBankAccount(): ChartOfAccount
    {
        $account = ChartOfAccount::query()
            ->whereIn('id', DB::table('bank_accounts')->whereNull('deleted_at')->select('chart_account_id'))
            ->where('status', ActiveStatus::Active)
            ->orderBy('id')
            ->first();

        if ($account === null) {
            throw new RuntimeException('No active bank account is configured. Run BankAccountSeeder.');
        }

        return $account;
    }

    /**
     * Recomputes the cached balance for the given accounts, from the lines.
     *
     * §2.7 calls `account_balances` "a materialized cache, rebuilt from
     * journal_entry_lines" — so it is always DERIVED, never incremented in
     * place. Recomputing from source means a cache row can never drift from
     * the ledger it summarises, which is the failure mode that makes a
     * balance cache worse than no cache at all.
     *
     * @param list<int> $accountIds
     */
    public function refreshBalances(array $accountIds): void
    {
        if ($accountIds === []) {
            return;
        }

        $accounts = ChartOfAccount::query()->whereIn('id', $accountIds)->get()->keyBy('id');

        /*
         * Per (account, branch) so branch-scoped reporting reads straight off
         * the cache. There is no separate account-wide row: the NULL-branch row
         * holds only the lines that carry no branch, and the account total is
         * the sum of every row (ChartOfAccount::cachedBalance).
         */
        $rows = DB::table('journal_entry_lines')
            ->select('account_id', 'branch_id')
            ->selectRaw('SUM(debit_amount) AS debit_total, SUM(credit_amount) AS credit_total')
            ->whereIn('account_id', $accountIds)
            ->groupBy('account_id', 'branch_id')
            ->get();

        $now = Date::now();

        foreach ($rows as $row) {
            $account = $accounts->get($row->account_id);

            if ($account === null) {
                continue;
            }

            $debit = Money::of((string) $row->debit_total);
            $credit = Money::of((string) $row->credit_total);

            AccountBalance::query()->updateOrCreate(
                ['account_id' => $row->account_id, 'branch_id' => $row->branch_id],
                [
                    'debit_total' => $debit->toDecimalString(),
                    'credit_total' => $credit->toDecimalString(),
                    'balance' => $this->netBalance($account->type, $debit, $credit)->toDecimalString(),
                    'last_updated_at' => $now,
                ],
            );
        }
    }

    /**
     * Signed by the account's normal side — mirrors the frontend's
     * `netBalance`. Debit-normal accounts show Dr − Cr; everything else
     * Cr − Dr, so a healthy balance reads positive either way.
     */
    public function netBalance(AccountType $type, Money $debitTotal, Money $creditTotal): Money
    {
        return $type->isDebitNormal()
            ? $debitTotal->subtract($creditTotal)
            : $creditTotal->subtract($debitTotal);
    }

    /**
     * Rebuilds every cached balance from scratch.
     *
     * The cache is derived, so this is always safe to run — and it is what a
     * reconciliation job would call to prove the cache matches the ledger.
     */
    public function rebuildAllBalances(): void
    {
        $this->refreshBalances(ChartOfAccount::query()->pluck('id')->all());
    }
}
