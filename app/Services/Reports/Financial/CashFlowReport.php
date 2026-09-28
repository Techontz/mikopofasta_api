<?php

namespace App\Services\Reports\Financial;

use App\Enums\Account;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * MASTER CASHFLOW / TREASURY REPORT ("MOYO FINANCE") and DAILY POSITION REPORT.
 *
 * Cash = every money-holding asset account (branch fund accounts, teller cash, agent, banks, HQ and
 * company accounts, staff fund cash). Receivables (loan portfolio, staff credit) are not cash.
 *
 * Inferred: money received but not yet matched (SUSPENSE ACCOUNT) is not available cash — the
 * suspense liability is netted against cash and treated as an HQ clearing account, so a receipt is
 * counted once, when it reaches a loan (or immediately, for receipts posted straight to a loan).
 * Movements between two cash accounts in scope (internal transfers) are not flows; with a single
 * branch or HQ selected they appear as "Transfers in / out".
 *
 * Every entry's cash movement is explained by its non-cash lines, which give the inflow / outflow
 * category (loan repayments, penalties, fees, recoveries, staff credit, funding / disbursement,
 * branch vs HQ expenses, salaries, commission, staff loans & advances …).
 */
class CashFlowReport
{
    /**
     * Receivable/adjustment asset accounts that do not hold money ({@see CashAccounts}).
     *
     * @var list<Account>
     */
    public const NON_CASH_ASSETS = CashAccounts::NON_CASH_ASSETS;

    /**
     * Category key → [inflow label, outflow label].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const CATEGORIES = [
        'loan_principal' => ['Loan repayments (principal)', 'Loan disbursement'],
        'interest' => ['Loan repayments (interest)', 'Interest refunded'],
        'salary_advance' => ['Salary advance income', 'Salary advance income refunded'],
        'penalty' => ['Penalties', 'Penalties refunded'],
        'fees' => ['Fees', 'Fees refunded'],
        'insurance' => ['Insurance', 'Insurance refunded'],
        'recovery' => ['Recovery from defaults', 'Recovery reversed'],
        'loan_other' => ['Loan adjustments', 'Loan adjustments'],
        'staff_credit' => ['Staff loan & advance repayments', 'Staff loans & advances'],
        'funding' => ['External funding (capital)', 'Capital withdrawn'],
        'savings' => ['Customer savings deposits', 'Savings withdrawals'],
        'staff_fund' => ['Staff fund contributions', 'Staff fund withdrawals'],
        'salaries' => ['Salary recoveries', 'Salaries'],
        'commission' => ['Commission recovered', 'Commission payments'],
        'branch_expense' => ['Branch expenses refunded', 'Branch expenses'],
        'hq_expense' => ['HQ expenses refunded', 'HQ expenses'],
        'bank_charges' => ['Bank charges refunded', 'Bank charges'],
        'dividends' => ['Dividends returned', 'Dividend payments'],
        'other' => ['Other inflows', 'Other outflows'],
        'transfer' => ['Transfers in', 'Transfers out'],
    ];

    /**
     * Full report: opening, categorised inflows/outflows, transactions with running balance, closing.
     *
     * @return array<string, mixed>
     */
    public function build(FinancialScope $scope): array
    {
        $opening = $this->balance($scope, $scope->from->subDay());
        $entries = $this->entries($scope, $scope->from, $scope->to);

        $inflows = [];
        $outflows = [];
        $rows = [];
        $daily = [];
        $balance = $opening;

        foreach ($entries as $entry) {
            foreach ($entry['categories'] as $category => $amount) {
                if ($amount > 0) {
                    $inflows[$category] = round(($inflows[$category] ?? 0) + $amount, 2);
                } elseif ($amount < 0) {
                    $outflows[$category] = round(($outflows[$category] ?? 0) - $amount, 2);
                }
            }

            $inflow = $entry['net'] > 0 ? $entry['net'] : 0.0;
            $outflow = $entry['net'] < 0 ? -$entry['net'] : 0.0;
            $balance = round($balance + $entry['net'], 2);

            $day = $entry['date'];
            $daily[$day] ??= ['date' => $day, 'inflow' => 0.0, 'outflow' => 0.0, 'balance' => 0.0];
            $daily[$day]['inflow'] = round($daily[$day]['inflow'] + $inflow, 2);
            $daily[$day]['outflow'] = round($daily[$day]['outflow'] + $outflow, 2);
            $daily[$day]['balance'] = $balance;

            $rows[] = [
                'id' => $entry['id'],
                'date' => $day,
                'reference' => $entry['reference'],
                'description' => $entry['description'],
                'category' => $this->label((string) array_key_first($entry['dominant']), $entry['net']),
                'inflow' => $inflow,
                'outflow' => $outflow,
                'balance' => $balance,
                'branch' => $entry['branch'],
                'approved_by' => $entry['employee'],
                'is_reversal' => $entry['is_reversal'],
            ];
        }

        $totalIn = round(array_sum($inflows), 2);
        $totalOut = round(array_sum($outflows), 2);
        $cash = $this->balance($scope, $scope->to, withSuspense: false);

        return [
            'opening' => $opening,
            'inflows' => $this->categoryRows($inflows, 0),
            'outflows' => $this->categoryRows($outflows, 1),
            'total_inflow' => $totalIn,
            'total_outflow' => $totalOut,
            'net_movement' => round($totalIn - $totalOut, 2),
            'closing' => round($opening + $totalIn - $totalOut, 2),
            'cash_held' => $cash,
            'suspense_held' => round($cash - $this->balance($scope, $scope->to), 2),
            'transactions' => $rows,
            'daily' => array_values($daily),
        ];
    }

    /**
     * Daily position: per day and per branch (plus HQ) cash in / out / net and closing.
     *
     * @param  array<int, string>  $branchNames
     * @return array<string, mixed>
     */
    public function position(FinancialScope $scope, array $branchNames): array
    {
        $opening = $this->balance($scope, $scope->from->subDay());
        $entries = $this->entries($scope, $scope->from, $scope->to);

        $days = [];
        for ($day = $scope->from; $day->lte($scope->to); $day = $day->addDay()) {
            $days[$day->toDateString()] = ['date' => $day->toDateString(), 'opening' => 0.0, 'cash_in' => 0.0, 'cash_out' => 0.0, 'net' => 0.0, 'closing' => 0.0];
        }
        foreach ($entries as $entry) {
            $key = $entry['net'] > 0 ? 'cash_in' : 'cash_out';
            $days[$entry['date']][$key] = round($days[$entry['date']][$key] + abs($entry['net']), 2);
        }
        $running = $opening;
        foreach ($days as $date => $row) {
            $days[$date]['opening'] = $running;
            $days[$date]['net'] = round($row['cash_in'] - $row['cash_out'], 2);
            $running = round($running + $days[$date]['net'], 2);
            $days[$date]['closing'] = $running;
        }

        $branches = [];
        $targets = collect($branchNames)->filter(fn (string $name, int $id): bool => $scope->contains($id))->all();
        if ($scope->includeHq) {
            $targets = [0 => 'HQ (COMPANY)'] + $targets;
        }
        foreach ($targets as $branchId => $name) {
            $single = $branchId === 0
                ? new FinancialScope($scope->companyId, [], true, $scope->from, $scope->to, $name)
                : new FinancialScope($scope->companyId, [$branchId], false, $scope->from, $scope->to, $name);
            $branchOpening = $this->balance($single, $scope->from->subDay());
            $in = 0.0;
            $out = 0.0;
            foreach ($this->entries($single, $scope->from, $scope->to) as $entry) {
                $entry['net'] > 0 ? $in += $entry['net'] : $out -= $entry['net'];
            }
            $branches[] = [
                'branch_id' => $branchId === 0 ? 'hq' : (string) $branchId,
                'branch' => $name,
                'opening' => $branchOpening,
                'cash_in' => round($in, 2),
                'cash_out' => round($out, 2),
                'net' => round($in - $out, 2),
                'closing' => round($branchOpening + $in - $out, 2),
            ];
        }

        $totalIn = round(array_sum(array_column($days, 'cash_in')), 2);
        $totalOut = round(array_sum(array_column($days, 'cash_out')), 2);

        return [
            'opening' => $opening,
            'cash_in' => $totalIn,
            'cash_out' => $totalOut,
            'net' => round($totalIn - $totalOut, 2),
            'closing' => round($opening + $totalIn - $totalOut, 2),
            'days' => array_values($days),
            'branches' => $branches,
        ];
    }

    /**
     * Available cash of the scope at the end of a day (cash accounts less suspense unless disabled).
     */
    public function balance(FinancialScope $scope, CarbonImmutable $until, bool $withSuspense = true): float
    {
        $query = DB::table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.company_id', $scope->companyId)
            ->whereDate('journal_entries.entry_date', '<=', $until->toDateString())
            ->where(function ($query) use ($scope, $withSuspense): void {
                $query->where(fn ($cash) => $scope->apply($cash->where('accounts.type', 'asset')->whereNotIn('accounts.key', $this->nonCashKeys()), 'accounts.branch_id'));
                if ($withSuspense && $scope->includeHq) {
                    $query->orWhere('accounts.key', Account::Suspense->value);
                }
            });

        return round((float) $query->sum(DB::raw('journal_lines.debit - journal_lines.credit')), 2);
    }

    /**
     * Entries with a cash movement in scope, oldest first.
     *
     * @return Collection<int, array{id: int, date: string, reference: string, description: string, branch: ?string, employee: ?string, is_reversal: bool, net: float, categories: array<string, float>, dominant: array<string, float>}>
     */
    public function entries(FinancialScope $scope, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $lines = DB::table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->leftJoin('branches', 'branches.id', '=', 'journal_entries.branch_id')
            ->leftJoin('employees', 'employees.id', '=', 'journal_entries.employee_id')
            ->where('journal_entries.company_id', $scope->companyId)
            ->whereBetween('journal_entries.entry_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id')
            ->select([
                'journal_entries.id', 'journal_entries.entry_date', 'journal_entries.reference', 'journal_entries.description',
                'journal_entries.reversal_of_id', 'branches.name AS branch_name',
                DB::raw("TRIM(CONCAT_WS(' ', employees.first_name, employees.middle_name, employees.last_name)) AS employee_name"),
                'accounts.key', 'accounts.type', 'accounts.branch_id', 'journal_lines.debit', 'journal_lines.credit',
            ])
            ->get()
            ->groupBy('id');

        $nonCash = $this->nonCashKeys();

        return $lines->map(function (Collection $entryLines) use ($scope, $nonCash): ?array {
            $net = 0.0;
            $categories = [];
            foreach ($entryLines as $line) {
                $isSuspense = $line->key === Account::Suspense->value;
                $isCash = $line->type === 'asset' && ! in_array($line->key, $nonCash, true);
                $amount = (float) $line->debit - (float) $line->credit;

                if (($isCash && $scope->contains($line->branch_id === null ? null : (int) $line->branch_id)) || ($isSuspense && $scope->includeHq)) {
                    $net += $amount;

                    continue;
                }

                $category = ($isCash || $isSuspense) ? 'transfer' : $this->category($line->key, $line->branch_id !== null);
                $categories[$category] = ($categories[$category] ?? 0) - $amount;
            }

            $net = round($net, 2);
            if (abs($net) < 0.005) {
                return null;
            }

            $categories = array_filter(array_map(fn (float $value): float => round($value, 2), $categories), fn (float $value): bool => abs($value) >= 0.005);
            $dominant = $categories;
            uasort($dominant, fn (float $a, float $b): int => abs($b) <=> abs($a));
            $first = $entryLines->first();

            return [
                'id' => (int) $first->id,
                'date' => substr((string) $first->entry_date, 0, 10),
                'reference' => (string) $first->reference,
                'description' => (string) $first->description,
                'branch' => $first->branch_name ?? 'HQ',
                'employee' => $first->employee_name ?: null,
                'is_reversal' => $first->reversal_of_id !== null,
                'net' => $net,
                'categories' => $categories,
                'dominant' => $dominant,
            ];
        })->filter()->values();
    }

    public function category(string $key, bool $branchTagged): string
    {
        return match (Account::tryFrom($key)) {
            Account::LoanReceivable => 'loan_principal',
            Account::InterestIncome, Account::OutstandingInterest => 'interest',
            Account::SalaryAdvanceIncome => 'salary_advance',
            Account::PenaltyIncome => 'penalty',
            Account::FeeIncome => 'fees',
            Account::InsuranceIncome => 'insurance',
            Account::RecoveryIncome => 'recovery',
            Account::LoanArrears, Account::LoanDefault, Account::Offset, Account::WriteOffExpense => 'loan_other',
            Account::SalaryAdvanceReceivable, Account::StaffLoanReceivable, Account::StaffAdvanceReceivable => 'staff_credit',
            Account::Capital => 'funding',
            Account::SavingsDeposits => 'savings',
            Account::StaffFund => 'staff_fund',
            Account::StaffPayable, Account::SalaryExpense, Account::AllowanceExpense => 'salaries',
            Account::CommissionExpense => 'commission',
            Account::OperatingExpense => $branchTagged ? 'branch_expense' : 'hq_expense',
            Account::BankCharges => 'bank_charges',
            Account::DividendPayable => 'dividends',
            default => 'other',
        };
    }

    private function label(string $category, float $net): string
    {
        $labels = self::CATEGORIES[$category] ?? self::CATEGORIES['other'];

        return $labels[$net >= 0 ? 0 : 1];
    }

    /**
     * @param  array<string, float>  $amounts
     * @return list<array{key: string, label: string, amount: float}>
     */
    private function categoryRows(array $amounts, int $side): array
    {
        $rows = [];
        foreach (self::CATEGORIES as $key => $labels) {
            if (isset($amounts[$key])) {
                $rows[] = ['key' => $key, 'label' => $labels[$side], 'amount' => $amounts[$key]];
            }
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function nonCashKeys(): array
    {
        return array_map(fn (Account $account): string => $account->value, self::NON_CASH_ASSETS);
    }
}
