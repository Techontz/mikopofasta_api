<?php

namespace App\Services\Credit;

use App\Enums\LoanStatus;
use App\Models\Loan;
use App\Services\Hrm\PerformanceMetrics;
use App\Services\Reports\InstalmentBehaviour;
use App\Services\Reports\PortfolioReports;
use App\Services\Reports\ReportScope;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The three CONTEXTUAL credit signals of the specification — customer type (§39), branch (§40) and loan officer (§41).
 *
 *  - customer type  the disbursed loans of every customer of the same customer type (customers.customer_category_id),
 *                   replayed by {@see InstalmentBehaviour}: repayment rate, late-payment rate, default rate and average
 *                   delay — the same definitions {@see PortfolioReports::segmentation()} uses, plus the late-payment
 *                   rate §39 asks for, read by customer type id instead of computing every segmentation dimension.
 *  - branch         {@see PortfolioReports::arrears()} (PAR 30, default rate) and {@see PortfolioReports::collections()}
 *                   (collection rate) for the application's own branch, plus the latest closed branch_period_results row.
 *  - officer        {@see PerformanceMetrics::forEmployees()} — collections against expected, and portfolio at risk.
 *
 * None of these is individual evidence: they describe the population a customer sits in. Every signal is read over
 * `credit.context.lookback_months` (which also bounds the queries), is `matched` only with at least
 * `credit.context.minimum_sample_loans` loans behind it, and is cached per day. Where a signal has no data the engine
 * falls back to neutral.
 */
class ContextualPerformance
{
    public function __construct(
        private readonly PortfolioReports $portfolio,
        private readonly PerformanceMetrics $officers,
        private readonly InstalmentBehaviour $behaviour,
    ) {}

    /**
     * §39: how the customer's own customer type has performed across the company.
     *
     * @return array{customer_type_id: int|null, segment: string, matched: bool, customers: int, loans: int, instalments_due: int, repayment_rate: float|null, late_payment_rate: float|null, default_rate: float|null, avg_delay_days: float|null, lookback_months: int, minimum_sample_loans: int}
     */
    public function customerType(Loan $loan, CarbonImmutable $today): array
    {
        $typeId = $loan->customer?->customer_category_id;
        $name = $loan->customer?->customerCategory?->name ?? 'No customer type';
        $from = $this->windowStart($today);

        $figures = $typeId === null ? null : $this->remember("type:{$loan->company_id}:{$typeId}", $today, function () use ($loan, $typeId, $from, $today): array {
            $loans = DB::table('loans')
                ->join('customers', 'customers.id', '=', 'loans.customer_id')
                ->where('loans.company_id', $loan->company_id)
                ->where('customers.customer_category_id', $typeId)
                ->whereIn('loans.status', LoanStatus::values(...LoanStatus::disbursed()))
                ->whereNotNull('loans.withdrawn_at')
                ->whereBetween('loans.withdrawn_at', [$from->toDateString(), $today->toDateString()])
                ->get(['loans.id', 'loans.customer_id', 'loans.status']);

            $due = $this->behaviour->instalments($loans->pluck('id')->map(fn ($id): int => (int) $id)->all(), $today)->where('is_due', true);
            $dueAmount = (float) $due->sum('amount');

            return [
                'customers' => $loans->pluck('customer_id')->unique()->count(),
                'loans' => $loans->count(),
                'instalments_due' => $due->count(),
                'repayment_rate' => $dueAmount > 0 ? round((float) $due->sum(fn (array $row): float => min($row['paid_amount'], $row['amount'])) / $dueAmount * 100, 1) : null,
                'late_payment_rate' => $due->isNotEmpty() ? round($due->filter(fn (array $row): bool => (int) $row['delay_days'] > 0)->count() / $due->count() * 100, 1) : null,
                'default_rate' => $loans->isNotEmpty() ? round($loans->whereIn('status', LoanStatus::values(LoanStatus::Default, LoanStatus::WrittenOff))->count() / $loans->count() * 100, 1) : null,
                'avg_delay_days' => $due->isNotEmpty() ? round((float) $due->avg(fn (array $row): int => max(0, (int) $row['delay_days'])), 1) : null,
            ];
        });

        $figures ??= ['customers' => 0, 'loans' => 0, 'instalments_due' => 0, 'repayment_rate' => null, 'late_payment_rate' => null, 'default_rate' => null, 'avg_delay_days' => null];

        return [
            'customer_type_id' => $typeId === null ? null : (int) $typeId,
            'segment' => $name,
            'matched' => $figures['loans'] >= $this->minimumSample() && $figures['instalments_due'] > 0,
            ...$figures,
            'lookback_months' => $this->lookbackMonths(),
            'minimum_sample_loans' => $this->minimumSample(),
        ];
    }

    /**
     * §40: collection and arrears quality of the branch the application belongs to.
     *
     * @return array{branch_id: int, branch: string|null, matched: bool, loans: int, portfolio: float|null, par30_rate: float|null, default_rate: float|null, expected_collections: float|null, collection_rate: float|null, last_period_net_profit: float|null, lookback_months: int, minimum_sample_loans: int}
     */
    public function branch(Loan $loan, CarbonImmutable $today): array
    {
        $branchId = (int) $loan->branch_id;
        $scope = (new ReportScope((int) $loan->company_id, [$branchId]))->withDates($this->windowStart($today), $today);

        $figures = $this->remember("branch:{$loan->company_id}:{$branchId}", $today, function () use ($scope, $today, $branchId): array {
            $arrears = $this->portfolio->arrears($scope, $today);
            $collections = $this->portfolio->collections($scope, 'monthly')['summary'];
            $loans = (int) DB::table('loans')->where('branch_id', $branchId)
                ->whereIn('status', LoanStatus::values(...LoanStatus::disbursed()))
                ->whereBetween('withdrawn_at', [$scope->from?->toDateString(), $today->toDateString()])
                ->count();
            $period = DB::table('branch_period_results')->where('branch_id', $branchId)->orderByDesc('accounting_period_id')->first(['net_profit']);
            $portfolio = (float) $arrears['summary']['portfolio'];
            $expected = (float) $collections['expected'];

            return [
                'loans' => $loans,
                'portfolio' => $portfolio,
                'par30_rate' => $portfolio > 0 ? (float) $arrears['summary']['par30_rate'] : null,
                'default_rate' => $loans > 0 ? (float) $arrears['summary']['default_rate'] : null,
                'expected_collections' => $expected,
                'collection_rate' => $expected > 0 ? (float) $collections['collection_rate'] : null,
                'last_period_net_profit' => $period === null ? null : (float) $period->net_profit,
            ];
        });

        return [
            'branch_id' => $branchId,
            'branch' => $loan->branch?->name,
            'matched' => $figures['loans'] >= $this->minimumSample() && ($figures['collection_rate'] !== null || $figures['par30_rate'] !== null),
            ...$figures,
            'lookback_months' => $this->lookbackMonths(),
            'minimum_sample_loans' => $this->minimumSample(),
        ];
    }

    /**
     * §41: the performance of the loan officer who owns the application, over the look-back window.
     *
     * @return array{employee_id: int|null, officer: string|null, matched: bool, loans: int, collection_rate: float|null, expected_collections: float|null, collections_amount: float|null, portfolio_outstanding: float|null, par30: float|null, lookback_months: int, minimum_sample_loans: int}
     */
    public function officer(Loan $loan, CarbonImmutable $today): array
    {
        $employee = $loan->employee;
        $empty = ['loans' => 0, 'collection_rate' => null, 'expected_collections' => null, 'collections_amount' => null, 'portfolio_outstanding' => null, 'par30' => null];

        $figures = $employee === null ? $empty : $this->remember("officer:{$loan->company_id}:{$employee->id}", $today, function () use ($employee, $today): array {
            $from = $this->windowStart($today);
            $row = $this->officers->forEmployees(new Collection([$employee]), $from, $today)[0] ?? null;
            $portfolio = (float) ($row['portfolio_outstanding'] ?? 0);

            return [
                'loans' => (int) Loan::where('employee_id', $employee->id)->status(...LoanStatus::disbursed())
                    ->whereBetween('withdrawn_at', [$from->toDateString(), $today->toDateString()])->count(),
                'collection_rate' => isset($row['collection_rate']) ? (float) $row['collection_rate'] : null,
                'expected_collections' => isset($row['expected_collections']) ? (float) $row['expected_collections'] : null,
                'collections_amount' => isset($row['collections_amount']) ? (float) $row['collections_amount'] : null,
                'portfolio_outstanding' => $portfolio,
                'par30' => $portfolio > 0 && isset($row['par30']) ? (float) $row['par30'] : null,
            ];
        });

        return [
            'employee_id' => $employee?->id,
            'officer' => $employee?->full_name,
            'matched' => $figures['loans'] >= $this->minimumSample() && ($figures['collection_rate'] !== null || $figures['par30'] !== null),
            ...$figures,
            'lookback_months' => $this->lookbackMonths(),
            'minimum_sample_loans' => $this->minimumSample(),
        ];
    }

    /**
     * Population figures move slowly, so they are cached for the day they describe.
     *
     * @template TValue of array
     *
     * @param  Closure(): TValue  $compute
     * @return TValue
     */
    private function remember(string $key, CarbonImmutable $today, Closure $compute): array
    {
        $seconds = (int) config('credit.context.cache_seconds');

        if ($seconds <= 0) {
            return $compute();
        }

        return Cache::remember("credit:context:{$key}:{$today->toDateString()}:{$this->lookbackMonths()}", $seconds, $compute);
    }

    private function windowStart(CarbonImmutable $today): CarbonImmutable
    {
        return $today->subMonths($this->lookbackMonths());
    }

    private function lookbackMonths(): int
    {
        return max(1, (int) config('credit.context.lookback_months'));
    }

    private function minimumSample(): int
    {
        return max(1, (int) config('credit.context.minimum_sample_loans'));
    }
}
