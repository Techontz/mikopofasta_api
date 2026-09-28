<?php

namespace App\Services\Reports\Financial;

use App\Enums\Account;
use App\Enums\LoanStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Balance Sheet (Simplified) — OVERVIEW ALL REPORT §7B:
 * Assets: loan portfolio, cash · Liabilities: staff fund, payables · Equity: capital, retained earnings.
 *
 * Balances are the ledger as of the date. Income and expense accounts not yet closed at month end are
 * shown in equity as "Current period earnings", so Assets = Liabilities + Equity always holds for the
 * whole company.
 *
 * Inferred: for a single branch or HQ the books are not self-balancing (capital sits at HQ and money moves
 * between HQ and branches), so the difference is shown as "HQ / inter-branch current account".
 */
class BalanceSheetReport
{
    /**
     * @var array<string, list<Account>>
     */
    public const ASSET_GROUPS = [
        'Loan portfolio' => [Account::LoanReceivable, Account::LoanArrears, Account::LoanDefault, Account::OutstandingInterest, Account::PenaltyReceivable],
        'Staff receivables' => [Account::SalaryAdvanceReceivable, Account::StaffLoanReceivable, Account::StaffAdvanceReceivable],
        'Other assets' => [Account::Offset],
        'Fixed assets' => [Account::MotorVehicles, Account::Equipment, Account::FurnitureFixtures, Account::Buildings, Account::Land, Account::OtherFixedAssets],
    ];

    /**
     * @return array<string, mixed>
     */
    public function build(FinancialScope $scope, CarbonImmutable $asOf): array
    {
        $query = DB::table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.company_id', $scope->companyId)
            ->whereDate('journal_entries.entry_date', '<=', $asOf->toDateString());

        $balances = $scope->apply($query, 'accounts.branch_id')
            ->groupBy('accounts.key')
            ->selectRaw('accounts.key AS account_key, SUM(journal_lines.debit) - SUM(journal_lines.credit) AS net')
            ->pluck('net', 'account_key');

        $amount = function (Account $account) use ($balances): float {
            $net = (float) ($balances[$account->value] ?? 0);

            return round($account->isDebitNormal() ? $net : -$net, 2) + 0.0;
        };
        $line = fn (Account $account): array => ['key' => $account->value, 'code' => $account->code(), 'label' => $account->label(), 'amount' => $amount($account)];
        $nonZero = fn (array $row): bool => abs($row['amount']) >= 0.005;

        $grouped = array_merge(...array_values(self::ASSET_GROUPS));
        $cash = array_values(array_filter(Account::cases(), fn (Account $account): bool => $account->type() === 'asset' && ! in_array($account, $grouped, true)));

        $assets = [['group' => 'Cash & bank', 'lines' => array_values(array_filter(array_map($line, $cash), $nonZero))]];
        foreach (self::ASSET_GROUPS as $group => $accounts) {
            $assets[] = ['group' => $group, 'lines' => array_values(array_filter(array_map($line, $accounts), $nonZero))];
        }
        foreach ($assets as $index => $group) {
            $assets[$index]['total'] = round(array_sum(array_column($group['lines'], 'amount')), 2);
        }
        $totalAssets = round(array_sum(array_column($assets, 'total')), 2);

        $liabilities = array_map($line, array_values(array_filter(Account::cases(), fn (Account $account): bool => $account->type() === 'liability')));
        $totalLiabilities = round(array_sum(array_column($liabilities, 'amount')), 2);

        $income = array_sum(array_map($amount, array_filter(Account::cases(), fn (Account $account): bool => $account->type() === 'income')));
        $expense = array_sum(array_map($amount, array_filter(Account::cases(), fn (Account $account): bool => $account->type() === 'expense')));

        $equity = [
            $line(Account::Capital),
            $line(Account::ReinvestedProfit),
            $line(Account::InterestReserve),
            $line(Account::InsuranceReserve),
            $line(Account::LegacyOpeningBalance),
            ['key' => 'retained_earnings', 'code' => Account::RetainedProfit->code(), 'label' => 'RETAINED EARNINGS', 'amount' => $amount(Account::RetainedProfit)],
            ['key' => 'current_earnings', 'code' => '', 'label' => 'CURRENT PERIOD EARNINGS', 'amount' => round($income - $expense, 2)],
        ];

        $difference = round($totalAssets - $totalLiabilities - array_sum(array_column($equity, 'amount')), 2);
        if (! $scope->isCompanyWide() && abs($difference) >= 0.005) {
            $equity[] = ['key' => 'inter_branch', 'code' => '', 'label' => 'HQ / INTER-BRANCH CURRENT ACCOUNT', 'amount' => $difference];
        }
        $totalEquity = round(array_sum(array_column($equity, 'amount')), 2);

        return [
            'as_of' => $asOf->toDateString(),
            'assets' => $assets,
            'total_assets' => $totalAssets,
            'liabilities' => $liabilities,
            'total_liabilities' => $totalLiabilities,
            'equity' => $equity,
            'total_equity' => $totalEquity,
            'total_liabilities_equity' => round($totalLiabilities + $totalEquity, 2),
            'difference' => round($totalAssets - $totalLiabilities - $totalEquity, 2),
            'balanced' => abs($totalAssets - $totalLiabilities - $totalEquity) < 0.005,
            'memo' => [
                'legacy_unaccrued_penalties' => $this->legacyUnaccruedPenalties($scope, $asOf),
                'legacy_unaccrued_penalties_label' => 'Legacy unaccrued penalties (cash basis, memo only — not in PENALTY RECEIVABLE)',
            ],
        ];
    }

    /**
     * Unpaid, not waived penalties charged before penalty accrual existed (no accrual journal) on loans that are not written off:
     * they are collected on a cash basis and never appear in PENALTY RECEIVABLE (user decision D9 — history is not backfilled).
     */
    private function legacyUnaccruedPenalties(FinancialScope $scope, CarbonImmutable $asOf): float
    {
        $query = DB::table('penalties')
            ->leftJoin('loans', 'loans.id', '=', 'penalties.loan_id')
            ->where('penalties.company_id', $scope->companyId)
            ->whereNull('penalties.accrual_journal_entry_id')
            ->where('penalties.is_waived', false)
            // A penalty carried over from the old system may stand on its own, with no loan of this system behind it.
            ->where(fn ($query) => $query->whereNull('loans.id')->orWhere('loans.status', '!=', LoanStatus::WrittenOff->value))
            ->whereDate('penalties.penalty_date', '<=', $asOf->toDateString());

        return round((float) $scope->apply($query, 'penalties.branch_id')->sum(DB::raw('penalties.amount - penalties.paid_amount')), 2) + 0.0;
    }
}
