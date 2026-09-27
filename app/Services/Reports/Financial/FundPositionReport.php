<?php

namespace App\Services\Reports\Financial;

use App\Enums\Account;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cash & Fund Position (C5): where the company's money sits on a date, read from the ledger only (no posting).
 *
 * In this ledger the fund accounts (PRINCIPAL, INTEREST, LOAN FEE, PENALTY, RESERVE, INSURANCE and the HQ accounts) are real
 * money-holding asset accounts, not a layer on top of cash: money received into a fund is debited to the fund, not to a cash
 * account. So every shilling sits in exactly ONE money account, either
 *
 *  1. PHYSICAL cash & bank — COMPANY ACCOUNT, each bank account, bank clearing (provider receipts, bank lines without a bank
 *     account), teller cash per branch, agent accounts per branch, staff fund cash; or
 *  2. a FUND account — per branch PRINCIPAL / INTEREST / LOAN FEE / PENALTY / RESERVE / INSURANCE A/C, and each HQ account;
 *
 * and the two sections add up to the total money ({@see CashAccounts::moneyAccounts()}). Section 3 lists reference balances
 * that are NOT money (equity and liabilities that say what the money is owed to or reserved for).
 *
 * The scope follows the other financial reports: company-wide, a branch, or HQ only (accounts without a branch).
 */
class FundPositionReport
{
    /**
     * Fund accounts, reported per branch. PRINCIPAL is HQ's — HQ funds every loan — so it appears on the "HQ (no branch)"
     * row; the others report the income each branch generated.
     *
     * @var list<Account>
     */
    public const BRANCH_FUNDS = [Account::Principal, Account::Interest, Account::LoanFee, Account::Penalty, Account::Reserve, Account::Insurance];

    /** @var list<Account> */
    public const REFERENCE = [Account::InterestReserve, Account::InsuranceReserve, Account::Capital, Account::ReinvestedProfit, Account::DividendPayable, Account::CommissionPayable];

    /**
     * @param  array<int, string>  $branchNames  branch id → name
     * @return array<string, mixed>
     */
    public function build(FinancialScope $scope, CarbonImmutable $asOf, array $branchNames): array
    {
        $keys = array_map(fn (Account $account): string => $account->value, array_merge(CashAccounts::moneyAccounts(), self::REFERENCE));
        $query = DB::table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.company_id', $scope->companyId)
            ->whereIn('accounts.key', $keys)
            ->whereDate('journal_entries.entry_date', '<=', $asOf->toDateString());

        /** @var list<object{account_key: string, branch_id: ?int, bank_account_id: ?int, net: string}> $rows */
        $rows = $scope->apply($query, 'accounts.branch_id')
            ->groupBy('accounts.key', 'accounts.branch_id', 'accounts.bank_account_id')
            ->selectRaw('accounts.key AS account_key, accounts.branch_id, accounts.bank_account_id, SUM(journal_lines.debit) - SUM(journal_lines.credit) AS net')
            ->get()
            ->all();

        $sum = function (Account $account, ?callable $filter = null) use ($rows): float {
            $net = 0.0;
            foreach ($rows as $row) {
                if ($row->account_key === $account->value && ($filter === null || $filter($row))) {
                    $net += (float) $row->net;
                }
            }

            return round($account->isDebitNormal() ? $net : -$net, 2) + 0.0;
        };
        $branchLabel = fn (?int $branchId): string => $branchId === null ? 'HQ (no branch)' : ($branchNames[$branchId] ?? "Branch #{$branchId}");
        $branchIds = fn (Account $account): array => array_values(array_unique(array_map(fn ($row): ?int => $row->branch_id === null ? null : (int) $row->branch_id, array_filter($rows, fn ($row): bool => $row->account_key === $account->value))));

        // Section 1 — physical cash & bank.
        $physical = [['key' => 'company_cash', 'label' => Account::Company->label(), 'account' => Account::Company->value, 'branch_id' => null, 'bank_account_id' => null, 'amount' => $sum(Account::Company)]];
        $bankNames = DB::table('bank_accounts')->where('company_id', $scope->companyId)->orderBy('id')->pluck('name', 'id')->all();
        $bankIds = array_values(array_unique(array_merge(array_map('intval', array_keys($bankNames)), array_map(fn ($row): int => (int) $row->bank_account_id, array_filter($rows, fn ($row): bool => $row->account_key === Account::Bank->value && $row->bank_account_id !== null)))));
        foreach ($bankIds as $bankId) {
            $physical[] = ['key' => 'bank', 'label' => 'BANK — '.($bankNames[$bankId] ?? "#{$bankId}"), 'account' => Account::Bank->value, 'branch_id' => null, 'bank_account_id' => $bankId, 'amount' => $sum(Account::Bank, fn ($row): bool => (int) $row->bank_account_id === $bankId)];
        }
        $physical[] = ['key' => 'bank_clearing', 'label' => 'BANK CLEARING (provider receipts, no bank account)', 'account' => Account::Bank->value, 'branch_id' => null, 'bank_account_id' => null, 'amount' => $sum(Account::Bank, fn ($row): bool => $row->bank_account_id === null)];
        foreach ([[Account::TellerCash, 'teller_cash'], [Account::Agent, 'agent']] as [$account, $key]) {
            foreach ($branchIds($account) as $branchId) {
                $physical[] = ['key' => $key, 'label' => $account->label().' — '.$branchLabel($branchId), 'account' => $account->value, 'branch_id' => $branchId, 'bank_account_id' => null, 'amount' => $sum($account, fn ($row): bool => ($row->branch_id === null ? null : (int) $row->branch_id) === $branchId)];
            }
        }
        $physical[] = ['key' => 'staff_fund_cash', 'label' => Account::StaffFundCash->label(), 'account' => Account::StaffFundCash->value, 'branch_id' => null, 'bank_account_id' => null, 'amount' => $sum(Account::StaffFundCash)];
        $physicalTotal = round(array_sum(array_column($physical, 'amount')), 2) + 0.0;

        // Section 2 — money held in fund accounts.
        $fundBranchIds = [];
        foreach (self::BRANCH_FUNDS as $account) {
            $fundBranchIds = array_merge($fundBranchIds, $branchIds($account));
        }
        $fundBranchIds = array_values(array_unique($fundBranchIds, SORT_REGULAR));
        usort($fundBranchIds, fn (?int $left, ?int $right): int => [$left === null, $left] <=> [$right === null, $right]);
        $branches = [];
        foreach ($fundBranchIds as $branchId) {
            $funds = [];
            foreach (self::BRANCH_FUNDS as $account) {
                $funds[$account->value] = $sum($account, fn ($row): bool => ($row->branch_id === null ? null : (int) $row->branch_id) === $branchId);
            }
            $branches[] = ['branch_id' => $branchId, 'branch' => $branchLabel($branchId), 'funds' => $funds, 'total' => round(array_sum($funds), 2) + 0.0];
        }
        $fundTotals = [];
        foreach (self::BRANCH_FUNDS as $account) {
            $fundTotals[] = ['account' => $account->value, 'label' => $account->label(), 'amount' => round(array_sum(array_map(fn (array $branch): float => $branch['funds'][$account->value], $branches)), 2) + 0.0];
        }
        $branchFundsTotal = round(array_sum(array_column($fundTotals, 'amount')), 2) + 0.0;
        $hq = array_map(fn (Account $account): array => ['account' => $account->value, 'label' => $account->label(), 'amount' => $sum($account)], Account::hqAccounts());
        $hqTotal = round(array_sum(array_column($hq, 'amount')), 2) + 0.0;
        $fundsTotal = round($branchFundsTotal + $hqTotal, 2) + 0.0;

        // Section 3 — reference balances (not money).
        $reference = array_map(fn (Account $account): array => ['account' => $account->value, 'label' => $account->label(), 'type' => $account->type(), 'amount' => $sum($account)], self::REFERENCE);

        $moneyTotal = round($physicalTotal + $fundsTotal, 2) + 0.0;
        $ledgerMoneyTotal = round(array_sum(array_map(fn (Account $account): float => $sum($account), CashAccounts::moneyAccounts())), 2) + 0.0;

        return [
            'as_of' => $asOf->toDateString(),
            'physical' => ['lines' => $physical, 'total' => $physicalTotal],
            'funds' => [
                'branches' => $branches,
                'fund_totals' => $fundTotals,
                'branch_funds_total' => $branchFundsTotal,
                'hq_accounts' => $hq,
                'hq_total' => $hqTotal,
                'total' => $fundsTotal,
            ],
            'reference' => ['lines' => $reference, 'note' => 'Not money: these equity and liability balances say what money is reserved for or owed to; the money itself is in sections 1 and 2.'],
            'total_money' => $moneyTotal,
            'ledger_money_total' => $ledgerMoneyTotal,
            'balanced' => abs($moneyTotal - $ledgerMoneyTotal) < 0.005,
            'note' => 'Money sits in exactly one account: either physical cash/bank (section 1) or a fund account (section 2). Fund balances are not an extra layer on top of the cash, so the two sections are added, never compared.',
        ];
    }
}
