<?php

namespace App\Services\Reports\Financial;

use App\Enums\Account;
use App\Enums\LoanStatus;
use App\Models\AccountingPeriod;
use App\Services\PeriodClose;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Branch Profit & Loss, Consolidated P&L and Branch Ranking / Efficiency (OVERVIEW ALL REPORT §3, §7, §10).
 *
 * Figures come from ledger movements of income and expense accounts within the date range,
 * excluding month-end closing entries (source AccountingPeriod), with the same formula as the
 * month-end close (App\Services\PeriodClose):
 *
 *   + Interest (reserve already cut) + Fees + Penalties + Recoveries = Total Income
 *   − Expenses (all tagged)                                           = Gross Profit
 *   − Loss Carry Forward                                              = Net Profit
 */
class ProfitLossReport
{
    /** Income that belongs to profit. Insurance income is shown apart: it is not distributable (user decision D7). */
    public const INCOME_ACCOUNTS = [Account::InterestIncome, Account::SalaryAdvanceIncome, Account::FeeIncome, Account::PenaltyIncome, Account::RecoveryIncome];

    /** Raw figure key: insurance collected straight into INSURANCE RESERVE (rule 15, new collections — never income). */
    public const INSURANCE_RESERVE_COLLECTED = 'insurance_reserve_collected';

    /**
     * Raw per-branch figures (0 = HQ) for the range: net amount per account key, plus `reserve` (all interest reserve of the
     * range), `reserve_legacy` (the part still inside INTEREST INCOME, {@see InterestReserves}) and
     * {@see INSURANCE_RESERVE_COLLECTED} (net credits to INSURANCE RESERVE outside month-end closings).
     *
     * @return array<int, array<string, float>>
     */
    public function figures(FinancialScope $scope, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $from ??= $scope->from;
        $to ??= $scope->to;

        $totals = $this->base($scope, $from, $to)
            ->whereIn('accounts.type', ['income', 'expense'])
            ->groupBy('accounts.branch_id', 'accounts.key')
            ->selectRaw('COALESCE(accounts.branch_id, 0) AS branch, accounts.key AS account_key, SUM(journal_lines.debit) AS debits, SUM(journal_lines.credit) AS credits')
            ->get();

        $reserves = InterestReserves::byBranch(fn (): Builder => $this->base($scope, $from, $to));

        $result = [];
        foreach ($totals as $row) {
            $account = Account::tryFrom($row->account_key);
            if ($account === null) {
                continue;
            }
            $difference = (float) $row->credits - (float) $row->debits;
            $result[(int) $row->branch][$account->value] = round($account->isDebitNormal() ? -$difference : $difference, 2) + 0.0;
        }
        foreach ($reserves['total'] as $branch => $amount) {
            $result[$branch]['reserve'] = $amount;
        }
        foreach ($reserves['legacy'] as $branch => $amount) {
            $result[$branch]['reserve_legacy'] = $amount;
        }

        $insurance = $this->base($scope, $from, $to)
            ->where('accounts.key', Account::InsuranceReserve->value)
            ->groupBy('accounts.branch_id')
            ->selectRaw('COALESCE(accounts.branch_id, 0) AS branch, SUM(journal_lines.credit) - SUM(journal_lines.debit) AS amount')
            ->pluck('amount', 'branch');
        foreach ($insurance as $branch => $amount) {
            $result[(int) $branch][self::INSURANCE_RESERVE_COLLECTED] = round((float) $amount, 2) + 0.0;
        }

        return $result;
    }

    /**
     * Raw figures of the whole company (every branch and HQ added together) for a date range, closing entries excluded.
     * With $branchIds only those branches are added (HQ excluded).
     *
     * @param  list<int>|null  $branchIds
     * @return array<string, float>
     */
    public function companyFigures(int $companyId, CarbonImmutable $from, CarbonImmutable $to, ?array $branchIds = null): array
    {
        $raw = [];
        foreach ($this->figures(new FinancialScope($companyId, $branchIds, $branchIds === null, $from, $to)) as $values) {
            foreach ($values as $key => $amount) {
                $raw[$key] = round(($raw[$key] ?? 0) + $amount, 2);
            }
        }

        return $raw;
    }

    /**
     * One P&L row from raw figures.
     *
     * @param  array<string, float>  $raw
     * @return array<string, float>
     */
    public function row(array $raw, float $lossBroughtForward = 0.0): array
    {
        $value = fn (Account $account): float => (float) ($raw[$account->value] ?? 0);
        $reserve = (float) ($raw['reserve'] ?? 0);
        $interest = round($value(Account::InterestIncome) - (float) ($raw['reserve_legacy'] ?? 0), 2);
        $salaryAdvance = $value(Account::SalaryAdvanceIncome);
        $fees = $value(Account::FeeIncome);
        $penalty = $value(Account::PenaltyIncome);
        $recovery = $value(Account::RecoveryIncome);
        $total = round($interest + $salaryAdvance + $fees + $penalty + $recovery, 2);
        $expenses = round(array_sum(array_map($value, PeriodClose::EXPENSE_ACCOUNTS)), 2);
        $gross = round($total - $expenses, 2);
        $net = round($gross - $lossBroughtForward, 2);
        $hold = $net > 0 ? round($net * PeriodClose::HQ_HOLD_PERCENT / 100, 2) : 0.0;

        return [
            'interest_income' => $interest,
            'reserve_amount' => $reserve,
            'salary_advance_income' => $salaryAdvance,
            'fee_income' => $fees,
            'penalty_income' => $penalty,
            'recovery_income' => $recovery,
            'total_income' => $total,
            'expenses' => $expenses,
            'gross_profit' => $gross,
            'loss_brought_forward' => $lossBroughtForward,
            'net_profit' => $net,
            'loss_carried_forward' => $net < 0 ? -$net : 0.0,
            'hq_hold_amount' => $hold,
            'distributable_profit' => $net > 0 ? round($net - $hold, 2) : 0.0,
        ];
    }

    /**
     * Branch Profit & Loss: one row per branch in scope (plus HQ for company-wide scope).
     *
     * Loss brought forward = loss carried forward of the last month-end result before "from".
     *
     * @param  array<int, string>  $branchNames
     * @return array{rows: list<array<string, mixed>>, totals: array<string, float>}
     */
    public function branchPnl(FinancialScope $scope, array $branchNames): array
    {
        $figures = $this->figures($scope);
        $losses = $this->lossesBefore($scope->companyId, $scope->from);

        $rows = [];
        foreach ($branchNames as $branchId => $name) {
            if (! $scope->contains($branchId)) {
                continue;
            }
            $rows[] = ['branch_id' => (string) $branchId, 'branch' => $name] + $this->row($figures[$branchId] ?? [], (float) ($losses[$branchId] ?? 0));
        }
        if ($scope->includeHq) {
            $hq = $this->row($figures[0] ?? []);
            $hq['hq_hold_amount'] = 0.0;
            $hq['distributable_profit'] = 0.0;
            $rows[] = ['branch_id' => 'hq', 'branch' => 'HQ (COMPANY)'] + $hq;
        }

        $totals = [];
        foreach ($rows as $row) {
            foreach ($row as $key => $value) {
                if (is_float($value)) {
                    $totals[$key] = round(($totals[$key] ?? 0) + $value, 2);
                }
            }
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * Consolidated P&L: income by account, expenses by account (operating expenses by expense type), monthly trend.
     *
     * Interest is shown before the reserve (ledger interest income + reserve credited to INTEREST RESERVE by new entries), less
     * the whole reserve. Insurance collections are listed below the net profit as "not distributable" (rule 15) — both kinds:
     * legacy INSURANCE INCOME (closed to INSURANCE RESERVE at month end) and new collections credited straight to INSURANCE
     * RESERVE — so the consolidated net profit follows the branch result definition (month-end close) and excludes insurance.
     * The HQ 2 % hold is not applied here (it is applied once, in the branch results / distributable profit, rule 16).
     *
     * @return array<string, mixed>
     */
    public function consolidated(FinancialScope $scope): array
    {
        $raw = [];
        foreach ($this->figures($scope) as $values) {
            foreach ($values as $key => $amount) {
                $raw[$key] = round(($raw[$key] ?? 0) + $amount, 2);
            }
        }

        $reserve = (float) ($raw['reserve'] ?? 0);
        $newReserve = round($reserve - (float) ($raw['reserve_legacy'] ?? 0), 2);
        $income = [];
        foreach (self::INCOME_ACCOUNTS as $account) {
            $amount = (float) ($raw[$account->value] ?? 0);
            $income[] = ['key' => $account->value, 'code' => $account->code(), 'label' => $account->label(), 'amount' => $account === Account::InterestIncome ? round($amount + $newReserve, 2) : $amount];
        }
        $grossIncome = round(array_sum(array_column($income, 'amount')), 2);
        $totalIncome = round($grossIncome - $reserve, 2);

        $expenseTypes = $this->base($scope, $scope->from, $scope->to)
            ->where('accounts.key', Account::OperatingExpense->value)
            ->leftJoin('expense_types', 'expense_types.id', '=', 'accounts.expense_type_id')
            ->groupBy('expense_types.name')
            ->selectRaw("COALESCE(expense_types.name, 'OTHER EXPENSES') AS name, SUM(journal_lines.debit) - SUM(journal_lines.credit) AS amount")
            ->orderBy('name')
            ->get()
            ->map(fn (object $row): array => ['label' => (string) $row->name, 'amount' => round((float) $row->amount, 2)])
            ->filter(fn (array $row): bool => abs($row['amount']) >= 0.005)
            ->values()
            ->all();

        $expenses = [];
        foreach (PeriodClose::EXPENSE_ACCOUNTS as $account) {
            $expenses[] = [
                'key' => $account->value,
                'code' => $account->code(),
                'label' => $account->label(),
                'amount' => (float) ($raw[$account->value] ?? 0),
                'breakdown' => $account === Account::OperatingExpense ? $expenseTypes : [],
            ];
        }
        $totalExpenses = round(array_sum(array_column($expenses, 'amount')), 2);

        $months = [];
        for ($month = $scope->from->startOfMonth(); $month->lte($scope->to); $month = $month->addMonth()) {
            $start = $month->lt($scope->from) ? $scope->from : $month;
            $end = $month->endOfMonth()->startOfDay()->gt($scope->to) ? $scope->to : $month->endOfMonth()->startOfDay();
            $sum = ['income' => 0.0, 'expenses' => 0.0];
            foreach ($this->figures($scope, $start, $end) as $values) {
                foreach (self::INCOME_ACCOUNTS as $account) {
                    $sum['income'] += (float) ($values[$account->value] ?? 0);
                }
                $sum['income'] -= (float) ($values['reserve_legacy'] ?? 0);
                foreach (PeriodClose::EXPENSE_ACCOUNTS as $account) {
                    $sum['expenses'] += (float) ($values[$account->value] ?? 0);
                }
            }
            $months[] = ['month' => $month->format('Y-m'), 'income' => round($sum['income'], 2), 'expenses' => round($sum['expenses'], 2), 'net_profit' => round($sum['income'] - $sum['expenses'], 2)];
        }

        return [
            'income' => $income,
            'gross_income' => $grossIncome,
            'reserve_amount' => $reserve,
            'total_income' => $totalIncome,
            'expenses' => $expenses,
            'total_expenses' => $totalExpenses,
            'net_profit' => round($totalIncome - $totalExpenses, 2),
            'insurance_income' => [
                'key' => Account::InsuranceReserve->value,
                'code' => Account::InsuranceReserve->code(),
                'label' => 'INSURANCE COLLECTIONS',
                'amount' => round((float) ($raw[Account::InsuranceIncome->value] ?? 0) + (float) ($raw[self::INSURANCE_RESERVE_COLLECTED] ?? 0), 2),
                'legacy_income_amount' => (float) ($raw[Account::InsuranceIncome->value] ?? 0),
                'reserve_amount' => (float) ($raw[self::INSURANCE_RESERVE_COLLECTED] ?? 0),
                'note' => 'Not distributable: held in INSURANCE RESERVE; legacy insurance income is closed to it at month end.',
            ],
            'months' => $months,
        ];
    }

    /**
     * Branch Ranking / Efficiency / Risk: best to worst by net profit, then by profit-vs-expense ratio.
     *
     * @param  array<int, string>  $branchNames
     * @return list<array<string, mixed>>
     */
    public function ranking(FinancialScope $scope, array $branchNames): array
    {
        $pnl = collect($this->branchPnl($scope, $branchNames)['rows'])->where('branch_id', '!=', 'hq');
        $ids = $pnl->pluck('branch_id')->map(fn (string $id): int => (int) $id)->all();

        $issued = DB::table('loans')
            ->where('company_id', $scope->companyId)
            ->whereIn('branch_id', $ids === [] ? [0] : $ids)
            ->whereNotNull('disbursed_at')
            ->whereBetween('disbursed_at', [$scope->from->toDateString(), $scope->to->toDateString().' 23:59:59'])
            ->groupBy('branch_id')
            ->selectRaw('branch_id, COUNT(*) AS loans, SUM(amount_approved) AS amount')
            ->get()
            ->keyBy('branch_id');

        $portfolio = DB::table('loans')
            ->where('company_id', $scope->companyId)
            ->whereIn('branch_id', $ids === [] ? [0] : $ids)
            ->whereNotNull('disbursed_at')
            ->whereDate('disbursed_at', '<=', $scope->to->toDateString())
            ->groupBy('branch_id')
            ->selectRaw('branch_id, COUNT(*) AS loans, SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) AS defaulted', [LoanStatus::Default->value, LoanStatus::WrittenOff->value])
            ->get()
            ->keyBy('branch_id');

        $staff = DB::table('employees')
            ->where('company_id', $scope->companyId)
            ->where('account_type', '!=', 'shareholder')
            ->whereIn('branch_id', $ids === [] ? [0] : $ids)
            ->where('status', 'active')
            ->groupBy('branch_id')
            ->selectRaw('branch_id, COUNT(*) AS staff')
            ->pluck('staff', 'branch_id');

        $rows = $pnl->map(function (array $row) use ($issued, $portfolio, $staff): array {
            $id = (int) $row['branch_id'];
            $loans = (int) ($issued[$id]->loans ?? 0);
            $staffCount = (int) ($staff[$id] ?? 0);
            $portfolioLoans = (int) ($portfolio[$id]->loans ?? 0);

            return [
                'branch_id' => $row['branch_id'],
                'branch' => $row['branch'],
                'total_income' => $row['total_income'],
                'expenses' => $row['expenses'],
                'gross_profit' => $row['gross_profit'],
                'loss_brought_forward' => $row['loss_brought_forward'],
                'net_profit' => $row['net_profit'],
                'profit_expense_ratio' => $row['expenses'] > 0 ? round($row['gross_profit'] / $row['expenses'], 2) : null,
                'expense_income_percent' => $row['total_income'] > 0 ? round($row['expenses'] / $row['total_income'] * 100, 2) : null,
                'loans_issued' => $loans,
                'amount_issued' => round((float) ($issued[$id]->amount ?? 0), 2),
                'cost_per_loan' => $loans > 0 ? round($row['expenses'] / $loans, 2) : null,
                'staff' => $staffCount,
                'revenue_per_staff' => $staffCount > 0 ? round($row['total_income'] / $staffCount, 2) : null,
                'default_rate' => $portfolioLoans > 0 ? round((int) $portfolio[$id]->defaulted / $portfolioLoans * 100, 2) : 0.0,
            ];
        })->sort(function (array $a, array $b): int {
            return [$b['net_profit'], $b['profit_expense_ratio'] ?? PHP_FLOAT_MAX] <=> [$a['net_profit'], $a['profit_expense_ratio'] ?? PHP_FLOAT_MAX];
        })->values();

        $count = $rows->count();

        return $rows->map(fn (array $row, int $index): array => ['rank' => $index + 1] + $row + [
            'performance' => $count < 2 ? 'ONLY BRANCH' : ($index === 0 ? 'BEST' : ($index === $count - 1 ? 'WORST' : ($row['net_profit'] < 0 ? 'LOSS' : 'PROFIT'))),
        ])->all();
    }

    /**
     * Loss carried forward per branch from the latest month-end result that ended before $date.
     *
     * @return array<int, float>
     */
    public function lossesBefore(int $companyId, CarbonImmutable $date): array
    {
        $latest = AccountingPeriod::query()
            ->where('company_id', $companyId)
            ->whereDate('period_end', '<', $date->toDateString())
            ->orderByDesc('period_start')
            ->first();

        return $latest?->results()->pluck('loss_carried_forward', 'branch_id')->map(fn ($value): float => (float) $value)->all() ?? [];
    }

    private function base(FinancialScope $scope, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        $query = DB::table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.company_id', $scope->companyId)
            ->whereBetween('journal_entries.entry_date', [$from->toDateString(), $to->toDateString()]);

        return $scope->apply(ClosingEntries::exclude($query), 'accounts.branch_id');
    }
}
