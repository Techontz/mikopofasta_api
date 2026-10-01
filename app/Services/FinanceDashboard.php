<?php

namespace App\Services;

use App\Enums\Account;
use App\Enums\LoanStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Loan;
use App\Services\Approvals\PendingApprovals;
use App\Services\Reports\Financial\CashFlowReport;
use App\Services\Reports\Financial\FinancialScope;
use App\Services\Reports\Financial\ProfitLossReport;
use App\Services\Reports\PortfolioReports;
use App\Services\Reports\ReportScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finance Dashboard: one month (and optionally one branch) of the company's finances, every figure from the records and
 * the existing reports so it matches them:
 *
 *  - cash: the Cash Flow report (opening, cash in, cash out, closing); the Total Cash card is the closing balance;
 *  - disbursed / collected today: loan disbursements and loan repayments of the day, reversals excluded, against yesterday;
 *  - loan outstanding: the Loan Portfolio report total; its change is the movement of the loan receivable accounts since
 *    the end of last month;
 *  - expected / actual / arrears / default: the Repayment (collections) and Arrears reports. Expected = instalments due in
 *    the month; actual = repayments received in it, split into money received ("cash") and repayments settled by a top-up
 *    offset; arrears = overdue instalments of loans still within term; default = what DEFAULT loans still owe;
 *  - payment methods and banks: the channel of each repayment (bank, mobile money, cash, offset) and, for payments, the
 *    bank or network that received it. A customer's expected instalments count under the channel they last repaid through;
 *  - income vs expenses: the Profit & Loss ledger figures of the month against last month;
 *  - pending approvals: the groups of the Pending Approvals page.
 */
final class FinanceDashboard
{
    /**
     * Repayment methods recorded for mobile money (teller MNO, recovery networks).
     *
     * @var list<string>
     */
    private const MOBILE_METHODS = ['MNO', 'MOBILE', 'VODACOM', 'AIRTEL', 'TIGO', 'HALOPESA', 'MPESA'];

    /**
     * Payment method key => label, in display order.
     *
     * @var array<string, string>
     */
    public const METHODS = [
        'bank' => 'Bank Transfer',
        'mobile' => 'Mobile Money',
        'cash' => 'Cash',
        'offset' => 'Internal Transfer',
        'other' => 'Other',
    ];

    public function __construct(
        private readonly PortfolioReports $portfolio,
        private readonly CashFlowReport $cashFlow,
        private readonly ProfitLossReport $profitLoss,
        private readonly PendingApprovals $approvals,
        private readonly Ledger $ledger,
    ) {}

    /**
     * @param  list<int>|null  $branchIds  null = the whole company (with HQ)
     * @return array<string, mixed>
     */
    public function build(Company $company, Employee $viewer, CarbonImmutable $month, ?array $branchIds, CarbonImmutable $today): array
    {
        $from = $month->startOfMonth();
        $to = $month->endOfMonth();
        $previousFrom = $from->subMonthNoOverflow()->startOfMonth();
        $previousTo = $previousFrom->endOfMonth();
        $scope = new ReportScope((int) $company->id, $branchIds);
        $monthScope = $scope->withDates($from, $to);

        $cashFlow = $this->cashFlow->build(new FinancialScope((int) $company->id, $branchIds, $branchIds === null, $from, $to));
        $collections = $this->portfolio->collections($monthScope, 'monthly')['summary'];
        $portfolio = $this->portfolio->portfolio($scope)['summary'];
        $arrears = $this->portfolio->arrears($scope, $today);

        $expected = (float) $collections['expected'];
        $collected = (float) $collections['collected'];
        $offset = $this->offsetCollected($company, $branchIds, $from, $to);
        $arrearsWithinTerm = round((float) collect($arrears['rows'])->where('status', '!=', LoanStatus::Default->label())->sum('arrears'), 2);
        $defaultOutstanding = $this->defaultOutstanding($scope);

        $income = $this->incomeAndExpenses($company, $branchIds, $from, $to);
        $previousIncome = $this->incomeAndExpenses($company, $branchIds, $previousFrom, $previousTo);
        $receivable = fn (CarbonImmutable $until): float => $this->loanReceivable($company, $branchIds, $until);
        $receivableNow = $receivable($today->min($to));
        $receivableBefore = $receivable($from->subDay());

        return [
            'month' => $from->format('Y-m'),
            'month_label' => $from->format('F Y'),
            'cards' => [
                'cash_balance' => $cashFlow['closing'],
                'cash_balance_change' => $this->change($cashFlow['closing'], $cashFlow['opening']),
                'disbursed_today' => $this->disbursed($company, $branchIds, $today),
                'disbursed_today_change' => $this->change($this->disbursed($company, $branchIds, $today), $this->disbursed($company, $branchIds, $today->subDay())),
                'collected_today' => $this->collected($company, $branchIds, $today),
                'collected_today_change' => $this->change($this->collected($company, $branchIds, $today), $this->collected($company, $branchIds, $today->subDay())),
                'loan_outstanding' => (float) $portfolio['outstanding_total'],
                'loan_outstanding_change' => $this->change($receivableNow, $receivableBefore),
                'expected_month' => $expected,
                'collected_month' => $collected,
                'collected_month_percent' => $this->percent($collected, $expected),
                'arrears_within_term' => $arrearsWithinTerm,
                'arrears_percent' => $this->percent($arrearsWithinTerm, $expected),
                'default_outstanding' => $defaultOutstanding,
                'default_percent' => $this->percent($defaultOutstanding, $expected),
            ],
            'expected_vs_actual' => [
                'expected' => $expected,
                'actual' => $collected,
                'cash' => round(max(0, $collected - $offset), 2),
                'offset' => $offset,
                'outstanding' => round(max(0, $expected - $collected), 2),
            ],
            'payment_methods' => $this->paymentMethods($company, $branchIds, $from, $to),
            'channels' => $this->channels($company, $branchIds, $from, $to),
            'income_expenses' => $income + [
                'net_change' => $this->change($income['net'], $previousIncome['net']),
            ],
            'cash_flow' => [
                'opening' => $cashFlow['opening'],
                'cash_in' => $cashFlow['total_inflow'],
                'cash_out' => $cashFlow['total_outflow'],
                'closing' => $cashFlow['closing'],
            ],
            'approvals' => $this->approvals($viewer),
        ];
    }

    /**
     * What DEFAULT loans still owe (principal + interest + penalty), as the Loan Portfolio report counts it.
     */
    private function defaultOutstanding(ReportScope $scope): float
    {
        $loans = DB::table('loans')->where('company_id', $scope->companyId)
            ->when($scope->branchIds !== null, fn ($query) => $query->whereIn('branch_id', $scope->branchIds))
            ->whereIn('status', LoanStatus::values(LoanStatus::Default))
            ->pluck('id');

        return round((float) Loan::query()->whereKey($loans)->get()->sum(fn (Loan $loan): float => $loan->remaining_amount), 2);
    }

    /**
     * @param  list<int>|null  $branchIds
     */
    private function disbursed(Company $company, ?array $branchIds, CarbonImmutable $day): float
    {
        return round((float) $this->repayments($company, $branchIds, 'withdrawal')->whereDate('loan_transactions.transaction_date', $day->toDateString())->sum('loan_transactions.amount'), 2);
    }

    /**
     * @param  list<int>|null  $branchIds
     */
    private function collected(Company $company, ?array $branchIds, CarbonImmutable $day): float
    {
        return round((float) $this->repayments($company, $branchIds)->whereDate('loan_transactions.transaction_date', $day->toDateString())->sum('loan_transactions.amount'), 2);
    }

    /**
     * Loan transactions of a type (repayments by default), reversals excluded.
     *
     * @param  list<int>|null  $branchIds
     */
    private function repayments(Company $company, ?array $branchIds, string $type = 'deposit'): Builder
    {
        return DB::table('loan_transactions')
            ->where('loan_transactions.company_id', $company->id)
            ->when($branchIds !== null, fn ($query) => $query->whereIn('loan_transactions.branch_id', $branchIds))
            ->where('loan_transactions.type', $type)
            ->whereNull('loan_transactions.reversed_at');
    }

    /**
     * Repayments of the month settled by a top-up offset (the new loan paid the old one; no money changed hands).
     *
     * @param  list<int>|null  $branchIds
     */
    private function offsetCollected(Company $company, ?array $branchIds, CarbonImmutable $from, CarbonImmutable $to): float
    {
        return round((float) $this->repayments($company, $branchIds)
            ->whereBetween('loan_transactions.transaction_date', [$from->toDateString(), $to->toDateString().' 23:59:59'])
            ->whereIn('loan_transactions.id', DB::table('loan_offsets')->whereNull('reversed_at')->whereNotNull('loan_transaction_id')->select('loan_transaction_id'))
            ->sum('loan_transactions.amount'), 2);
    }

    /**
     * Every repayment of the month with its channel key and, for payments, the bank or network that received it.
     *
     * @param  list<int>|null  $branchIds
     * @return Collection<int, object{id: int, customer_id: int, amount: float, method: string, channel: string, provider: string}>
     */
    private function monthRepayments(Company $company, ?array $branchIds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $offsets = DB::table('loan_offsets')->whereNull('reversed_at')->whereNotNull('loan_transaction_id')->pluck('loan_transaction_id')->map(fn ($id): int => (int) $id)->flip();

        return $this->repayments($company, $branchIds)
            ->whereBetween('loan_transactions.transaction_date', [$from->toDateString(), $to->toDateString().' 23:59:59'])
            ->leftJoin('payment_allocations', fn ($join) => $join->on('payment_allocations.loan_transaction_id', '=', 'loan_transactions.id')->whereNull('payment_allocations.reversed_at'))
            ->leftJoin('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->get(['loan_transactions.id', 'loan_transactions.customer_id', 'loan_transactions.amount', 'loan_transactions.method', 'payments.channel', 'payments.provider'])
            ->unique('id')
            ->map(function (object $row) use ($offsets): object {
                $channel = $this->channelOf($row->channel ?? $row->method, $offsets->has((int) $row->id));

                return (object) [
                    'id' => (int) $row->id,
                    'customer_id' => (int) $row->customer_id,
                    'amount' => (float) $row->amount,
                    'channel' => $channel,
                    'provider' => $this->providerOf($channel, (string) ($row->provider ?? '')),
                ];
            })
            ->values();
    }

    private function channelOf(?string $method, bool $isOffset): string
    {
        $method = strtoupper((string) $method);

        return match (true) {
            $isOffset => 'offset',
            $method === 'BANK' => 'bank',
            in_array($method, self::MOBILE_METHODS, true) => 'mobile',
            $method === 'CASH' => 'cash',
            default => 'other',
        };
    }

    /**
     * The Collection by Bank / Channel row of a repayment: the bank's name for bank payments, otherwise the channel.
     */
    private function providerOf(string $channel, string $provider): string
    {
        return match ($channel) {
            'bank' => trim($provider) !== '' ? strtoupper(trim($provider)) : 'BANK (NOT NAMED)',
            'mobile' => 'Mobile Money',
            'cash' => 'Cash Collection',
            default => 'Other',
        };
    }

    /**
     * Collection by Payment Method: amount and share of each method over the month's repayments.
     *
     * @param  list<int>|null  $branchIds
     * @return array{total: float, rows: list<array{key: string, label: string, amount: float, percent: float}>}
     */
    private function paymentMethods(Company $company, ?array $branchIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $repayments = $this->monthRepayments($company, $branchIds, $from, $to);
        $total = round((float) $repayments->sum('amount'), 2);

        return [
            'total' => $total,
            'rows' => collect(self::METHODS)->map(fn (string $label, string $key): array => [
                'key' => $key,
                'label' => $label,
                'amount' => $amount = round((float) $repayments->where('channel', $key)->sum('amount'), 2),
                'percent' => $this->percent($amount, $total),
            ])->values()->all(),
        ];
    }

    /**
     * Collection by Bank / Channel: per bank (or mobile money, cash, other) the instalments expected this month from the
     * customers who last repaid through it, what was collected through it and how many customers paid through it.
     *
     * @param  list<int>|null  $branchIds
     * @return list<array{label: string, channel: string, expected: float, collected: float, clients: int}>
     */
    private function channels(Company $company, ?array $branchIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $repayments = $this->monthRepayments($company, $branchIds, $from, $to);

        $due = DB::table('loan_schedules')
            ->join('loans', 'loans.id', '=', 'loan_schedules.loan_id')
            ->where('loans.company_id', $company->id)
            ->when($branchIds !== null, fn ($query) => $query->whereIn('loans.branch_id', $branchIds))
            ->whereIn('loans.status', LoanStatus::values(...LoanStatus::disbursed()))
            ->whereBetween('loan_schedules.due_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('loans.customer_id')
            ->selectRaw('loans.customer_id, SUM(loan_schedules.amount) AS amount')
            ->pluck('amount', 'customer_id');

        // The channel each customer last repaid through (any month), so their expected instalments sit with it.
        $usual = $this->lastChannelOf($company, $due->keys()->map(fn ($id): int => (int) $id)->all());

        $rows = [];
        foreach ($due as $customerId => $amount) {
            $label = $usual[(int) $customerId] ?? ['Other', 'other'];
            $rows[$label[0]] ??= ['label' => $label[0], 'channel' => $label[1], 'expected' => 0.0, 'collected' => 0.0, 'clients' => 0];
            $rows[$label[0]]['expected'] = round($rows[$label[0]]['expected'] + (float) $amount, 2);
        }
        foreach ($repayments->groupBy('provider') as $provider => $items) {
            $rows[$provider] ??= ['label' => $provider, 'channel' => $items->first()->channel, 'expected' => 0.0, 'collected' => 0.0, 'clients' => 0];
            $rows[$provider]['collected'] = round((float) $items->sum('amount'), 2);
            $rows[$provider]['clients'] = $items->pluck('customer_id')->unique()->count();
        }

        $order = ['bank' => 0, 'mobile' => 1, 'cash' => 2, 'offset' => 3, 'other' => 4];

        return collect($rows)
            ->sortBy([fn (array $a, array $b): int => ($order[$a['channel']] ?? 9) <=> ($order[$b['channel']] ?? 9), fn (array $a, array $b): int => $b['collected'] <=> $a['collected']])
            ->values()
            ->all();
    }

    /**
     * customer id => [row label, channel] of their most recent repayment.
     *
     * @param  list<int>  $customerIds
     * @return array<int, array{0: string, 1: string}>
     */
    private function lastChannelOf(Company $company, array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $latest = DB::table('loan_transactions')
            ->where('company_id', $company->id)
            ->where('type', 'deposit')
            ->whereNull('reversed_at')
            ->whereIn('customer_id', $customerIds)
            ->groupBy('customer_id')
            ->selectRaw('customer_id, MAX(id) AS id')
            ->pluck('id', 'customer_id');
        $offsets = DB::table('loan_offsets')->whereNull('reversed_at')->whereIn('loan_transaction_id', $latest->values())->pluck('loan_transaction_id')->map(fn ($id): int => (int) $id)->flip();
        $details = DB::table('loan_transactions')
            ->whereIn('loan_transactions.id', $latest->values())
            ->leftJoin('payment_allocations', fn ($join) => $join->on('payment_allocations.loan_transaction_id', '=', 'loan_transactions.id')->whereNull('payment_allocations.reversed_at'))
            ->leftJoin('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->get(['loan_transactions.id', 'loan_transactions.customer_id', 'loan_transactions.method', 'payments.channel', 'payments.provider'])
            ->unique('id');

        $result = [];
        foreach ($details as $row) {
            $channel = $this->channelOf($row->channel ?? $row->method, $offsets->has((int) $row->id));
            $result[(int) $row->customer_id] = [$this->providerOf($channel, (string) ($row->provider ?? '')), $channel];
        }

        return $result;
    }

    /**
     * Income and expenses of a period from the ledger, as the Profit & Loss report counts them (closing entries excluded).
     *
     * @param  list<int>|null  $branchIds
     * @return array{income: list<array{key: string, label: string, amount: float}>, expenses: list<array{key: string, label: string, amount: float}>, total_income: float, total_expenses: float, net: float}
     */
    private function incomeAndExpenses(Company $company, ?array $branchIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $raw = $this->profitLoss->companyFigures((int) $company->id, $from, $to, $branchIds);
        $row = $this->profitLoss->row($raw);
        $value = fn (Account ...$accounts): float => round(array_sum(array_map(fn (Account $account): float => (float) ($raw[$account->value] ?? 0), $accounts)), 2);

        $income = [
            ['key' => 'interest', 'label' => 'Interest Income', 'amount' => (float) $row['interest_income']],
            ['key' => 'penalty', 'label' => 'Penalty Income', 'amount' => (float) $row['penalty_income']],
            ['key' => 'fee', 'label' => 'Loan Fee Income', 'amount' => (float) $row['fee_income']],
            ['key' => 'salary_advance', 'label' => 'Salary Advance Income', 'amount' => (float) $row['salary_advance_income']],
        ];
        if ((float) $row['recovery_income'] !== 0.0) {
            $income[] = ['key' => 'recovery', 'label' => 'Recovery Income', 'amount' => (float) $row['recovery_income']];
        }

        $expenses = [
            ['key' => 'salaries', 'label' => 'Salaries', 'amount' => $value(Account::SalaryExpense)],
            ['key' => 'operating', 'label' => 'Operating Expenses', 'amount' => $value(Account::OperatingExpense)],
            ['key' => 'commission', 'label' => 'Commission', 'amount' => $value(Account::CommissionExpense)],
            ['key' => 'other', 'label' => 'Other Expenses', 'amount' => $value(Account::AllowanceExpense, Account::WriteOffExpense, Account::BankCharges)],
        ];

        return [
            'income' => $income,
            'expenses' => $expenses,
            'total_income' => (float) $row['total_income'],
            'total_expenses' => (float) $row['expenses'],
            'net' => round((float) $row['total_income'] - (float) $row['expenses'], 2),
        ];
    }

    /**
     * Principal still owed on loans at the end of a day: the loan receivable accounts (current, arrears, default).
     *
     * @param  list<int>|null  $branchIds
     */
    private function loanReceivable(Company $company, ?array $branchIds, CarbonImmutable $until): float
    {
        $total = 0.0;
        foreach ([Account::LoanReceivable, Account::LoanArrears, Account::LoanDefault] as $account) {
            if ($branchIds === null) {
                $total += $this->ledger->balance($company, $account, until: $until, allBranches: true);

                continue;
            }
            foreach ($branchIds as $branchId) {
                $total += $this->ledger->balance($company, $account, $branchId, until: $until);
            }
        }

        return round($total, 2);
    }

    /**
     * Pending Approvals card: one line per workflow waiting, with the status most of its items are in.
     *
     * @return list<array{workflow: string, label: string, count: int, amount: float, status: string, link: string}>
     */
    private function approvals(Employee $viewer): array
    {
        return collect($this->approvals->forEmployee($viewer)['groups'])
            ->filter(fn (array $group): bool => $group['count'] > 0)
            ->map(fn (array $group): array => [
                'workflow' => $group['workflow'],
                'label' => $group['label'],
                'count' => $group['count'],
                'amount' => (float) $group['amount'],
                'status' => Str::headline((string) (collect($group['rows'])->countBy('status')->sortDesc()->keys()->first() ?? 'pending')),
                'link' => (string) ($group['rows'][0]['link'] ?? '/approvals'),
            ])
            ->values()
            ->all();
    }

    /**
     * Percentage change from the previous figure (null when there is nothing to compare with).
     */
    private function change(float $current, float $previous): ?float
    {
        return abs($previous) < 0.005 ? null : round(($current - $previous) / abs($previous) * 100, 1);
    }

    private function percent(float $part, float $whole): float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : 0.0;
    }
}
