<?php

namespace App\Services\Reports;

use App\Enums\LoanStatus;
use App\Models\SalaryAdvance;
use App\Models\WriteOff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Portfolio & Risk" reports (Documents: 🧠 OVERVIEW ALL REPORT — 2. LOAN & CUSTOMER REPORTS, SEGMENTATION,
 * AGE ANALYSIS, REPAYMENT BEHAVIOR). Outstanding balances always come from {@see LoanBalances}
 * (LoanService::outstanding() numbers, allocation Principal → Penalty → Interest).
 *
 * Inferred definitions (the Documents list the metrics but not the formulas):
 * - Portfolio = outstanding of ACTIVE / OVERDUE / DEFAULT loans; the date filter selects loans by withdrawal date.
 * - PARn = outstanding principal of loans whose oldest unpaid instalment is at least n days past due ÷ portfolio principal.
 * - Default rate = DEFAULT + WRITE-OFF loans ÷ cashed-out loans.
 * - Recovery = repayments received after the end date of a loan that is DEFAULT / WRITE-OFF (the live system marks DEFAULT
 *   once the end date passes with a balance), or of a CLOSED loan when received more than 30 days after its end date
 *   ("30+ → Default"), plus amounts recovered on write-offs; efficiency = recovered ÷ (recovered + balance still in
 *   default + write-offs not yet recovered).
 * - Collection rate = instalment part of repayments (amount − penalty) ÷ instalments due, per period.
 * - Repayment rate (segments / age) = paid on instalments already due ÷ amount of those instalments.
 * - Profit contribution = interest + penalty collected + loan fees deducted at disbursement.
 * - Customer rating: A always on time; B delays of at most 7 days; C delays of 8–30 days; D any delay over 30 days or a
 *   DEFAULT / WRITE-OFF loan. Pattern: Early payer (never late, average delay below 0), On-time payer (never late),
 *   Late payer, Chronic defaulter (rating D).
 */
class PortfolioReports
{
    /**
     * @var list<int>
     */
    public const PAR_DAYS = [1, 7, 30, 60, 90];

    /**
     * @var list<string>
     */
    public const AGE_BANDS = ['18–25', '26–35', '36–45', '46–60', '60+'];

    /**
     * @var list<string>
     */
    public const SEGMENT_DIMENSIONS = ['category', 'gender', 'age', 'occupation', 'region', 'branch', 'loan_size'];

    public function __construct(private readonly InstalmentBehaviour $behaviour) {}

    /**
     * Loan Portfolio: loans issued / active / completed / default and the outstanding portfolio per branch, product,
     * loan officer and customer type. Outstanding figures cover repayable loans only (active, overdue, default); written-off
     * loans are excluded from the portfolio (their principal left LOAN RECEIVABLE at write-off) and shown on their own
     * written-off line.
     *
     * @return array<string, mixed>
     */
    public function portfolio(ReportScope $scope): array
    {
        $loans = $this->loanDataset($scope, filterByWithdrawal: true);
        $repayable = $loans->filter(fn (object $loan): bool => $this->isRepayable($loan));
        $writtenOff = $loans->where('status', LoanStatus::WrittenOff->value);
        $portfolioPrincipal = (float) $repayable->sum('out_principal');
        // Penalties carried over from the old system with no loan behind them are outstanding penalty all the same; salary
        // advances are a debt of their own, shown beside the loan portfolio and never inside it.
        $penaltyWithoutLoan = round((float) $scope->between($scope->apply(DB::table('penalties'), 'penalties'), 'penalties.penalty_date')
            ->whereNull('penalties.loan_id')
            ->where('penalties.is_waived', false)
            ->sum(DB::raw('GREATEST(0, penalties.amount - penalties.paid_amount)')), 2);
        $salaryAdvances = SalaryAdvance::query()
            ->tap(fn ($query) => $scope->apply($query, 'salary_advances'))
            ->where('status', 'active')
            ->whereNull('reversed_at')
            ->withSum('payments', 'amount')
            ->get();
        $salaryAdvanceOutstanding = round((float) $salaryAdvances->sum(fn (SalaryAdvance $advance): float => $advance->remaining_amount), 2);
        $loanTotal = round((float) $repayable->sum('out_total') + $penaltyWithoutLoan, 2);

        $group = fn (string $key, string $label): array => $loans->groupBy($key)->map(function (Collection $items) use ($label, $portfolioPrincipal): array {
            $open = $items->filter(fn (object $loan): bool => $this->isRepayable($loan));
            $principal = round((float) $open->sum('out_principal'), 2);

            return [
                'label' => $items->first()->{$label} ?? 'Not set',
                'loans' => $items->count(),
                'active' => $items->whereIn('status', LoanStatus::values(LoanStatus::Active, LoanStatus::Overdue))->count(),
                'completed' => $items->where('status', LoanStatus::Closed->value)->count(),
                'default' => $items->where('status', LoanStatus::Default->value)->count(),
                'written_off' => $items->where('status', LoanStatus::WrittenOff->value)->count(),
                'written_off_outstanding' => round((float) $items->where('status', LoanStatus::WrittenOff->value)->sum('out_total'), 2),
                'disbursed' => round((float) $items->sum('amount_approved'), 2),
                'outstanding_principal' => $principal,
                'outstanding_total' => round((float) $open->sum('out_total'), 2),
                'share' => $this->percent($principal, $portfolioPrincipal),
            ];
        })->sortByDesc('outstanding_principal')->values()->all();

        return [
            'summary' => [
                'issued_count' => $loans->count(),
                'issued_amount' => round((float) $loans->sum('amount_approved'), 2),
                'active_count' => $loans->whereIn('status', LoanStatus::values(LoanStatus::Active, LoanStatus::Overdue))->count(),
                'overdue_count' => $loans->where('status', LoanStatus::Overdue->value)->count(),
                'completed_count' => $loans->where('status', LoanStatus::Closed->value)->count(),
                'default_count' => $loans->where('status', LoanStatus::Default->value)->count(),
                'written_off_count' => $loans->where('status', LoanStatus::WrittenOff->value)->count(),
                'active_customers' => $repayable->pluck('customer_id')->unique()->count(),
                'outstanding_principal' => round($portfolioPrincipal, 2),
                'outstanding_interest' => round((float) $repayable->sum('out_interest'), 2),
                'outstanding_penalty' => round((float) $repayable->sum('out_penalty') + $penaltyWithoutLoan, 2),
                'penalty_without_loan' => $penaltyWithoutLoan,
                'outstanding_insurance' => round((float) $repayable->sum('out_insurance'), 2),
                'outstanding_total' => $loanTotal,
                'active_outstanding_principal' => round((float) $repayable->whereIn('status', LoanStatus::values(LoanStatus::Active, LoanStatus::Overdue))->sum('out_principal'), 2),
                'default_outstanding_principal' => round((float) $repayable->where('status', LoanStatus::Default->value)->sum('out_principal'), 2),
                'salary_advance_outstanding' => $salaryAdvanceOutstanding,
                'customer_outstanding' => round($loanTotal + $salaryAdvanceOutstanding, 2),
                'old_system_loans' => $loans->where('is_legacy_opening', 1)->count(),
                'old_system_outstanding_principal' => round((float) $repayable->where('is_legacy_opening', 1)->sum('out_principal'), 2),
                'written_off_principal' => round((float) $writtenOff->sum('out_principal'), 2),
                'written_off_outstanding' => round((float) $writtenOff->sum('out_total'), 2),
            ],
            'by_branch' => $group('branch_id', 'branch_name'),
            'by_product' => $group('loan_category_id', 'product_name'),
            'by_officer' => $group('employee_id', 'officer_name'),
            'by_category' => $group('customer_category_id', 'category_name'),
        ];
    }

    /**
     * Repayment Report: expected vs actual collection per day / week / month and per branch, and the e-mandate success rate.
     *
     * @return array<string, mixed>
     */
    public function collections(ReportScope $scope, string $period): array
    {
        $statuses = LoanStatus::values(...LoanStatus::disbursed());

        $expected = $scope->between($scope->apply(DB::table('loan_schedules')->join('loans', 'loans.id', '=', 'loan_schedules.loan_id')->join('branches', 'branches.id', '=', 'loans.branch_id'), 'loans'), 'loan_schedules.due_date')
            ->whereIn('loans.status', $statuses)
            ->get(['loan_schedules.due_date as date', 'loan_schedules.amount', 'loan_schedules.paid_amount', 'loans.branch_id', 'branches.name as branch_name']);

        $actual = $scope->between($scope->apply(DB::table('loan_transactions')->join('branches', 'branches.id', '=', 'loan_transactions.branch_id'), 'loan_transactions'), 'loan_transactions.transaction_date')
            ->where('loan_transactions.type', 'deposit')
            ->whereNull('loan_transactions.reversed_at')
            ->get(['loan_transactions.transaction_date as date', 'loan_transactions.amount', 'loan_transactions.principal', 'loan_transactions.interest', 'loan_transactions.penalty', 'loan_transactions.insurance', 'loan_transactions.branch_id', 'branches.name as branch_name']);

        $key = fn (object $row): string => $this->periodKey(substr((string) $row->date, 0, 10), $period);
        $summarise = function (Collection $due, Collection $paid, string $label): array {
            $expectedAmount = round((float) $due->sum('amount'), 2);
            $collected = round((float) $paid->sum('amount'), 2);
            $instalments = round($collected - (float) $paid->sum('penalty'), 2);

            return [
                'label' => $label,
                'expected' => $expectedAmount,
                'due_paid' => round((float) $due->sum(fn (object $row): float => min((float) $row->paid_amount, (float) $row->amount)), 2),
                'collected' => $collected,
                'principal' => round((float) $paid->sum('principal'), 2),
                'penalty' => round((float) $paid->sum('penalty'), 2),
                'interest' => round((float) $paid->sum('interest'), 2),
                'insurance' => round((float) $paid->sum('insurance'), 2),
                'variance' => round($expectedAmount - $instalments, 2),
                'collection_rate' => $this->percent($instalments, $expectedAmount),
            ];
        };

        $dueByPeriod = $expected->groupBy($key);
        $paidByPeriod = $actual->groupBy($key);
        $periods = $dueByPeriod->keys()->merge($paidByPeriod->keys())->unique()->sort()->values();

        $dueByBranch = $expected->groupBy('branch_id');
        $paidByBranch = $actual->groupBy('branch_id');
        $branches = $dueByBranch->keys()->merge($paidByBranch->keys())->unique()->sort()->values();

        $mandates = $scope->apply(DB::table('loan_mandates')->join('loans', 'loans.id', '=', 'loan_mandates.loan_id'), 'loans')
            ->tap(fn ($query) => $scope->between($query, 'loan_mandates.created_at'))
            ->groupBy('loan_mandates.status')
            ->selectRaw('loan_mandates.status, COUNT(*) as total')
            ->get()
            ->pluck('total', 'status');
        $active = (int) ($mandates['active'] ?? 0);
        $failed = (int) ($mandates['failed'] ?? 0);

        return [
            'period' => $period,
            'summary' => $summarise($expected, $actual, 'TOTAL'),
            'rows' => $periods->map(fn (string $label): array => $summarise($dueByPeriod->get($label, collect()), $paidByPeriod->get($label, collect()), $label))->all(),
            'by_branch' => $branches->map(fn (int $branchId): array => $summarise(
                $dueByBranch->get($branchId, collect()),
                $paidByBranch->get($branchId, collect()),
                (string) ($dueByBranch->get($branchId)?->first()->branch_name ?? $paidByBranch->get($branchId)?->first()->branch_name),
            ))->values()->all(),
            'mandates' => [
                'total' => (int) $mandates->sum(),
                'active' => $active,
                'failed' => $failed,
                'pending' => (int) $mandates->sum() - $active - $failed,
                'success_rate' => $this->percent($active, $active + $failed),
            ],
        ];
    }

    /**
     * Default & Arrears: loans past due, days in arrears, PAR 1 / 7 / 30 / 60 / 90 and default rate per branch and officer.
     *
     * @return array<string, mixed>
     */
    public function arrears(ReportScope $scope, CarbonImmutable $today): array
    {
        $loans = $this->withArrears($this->loanDataset($scope, filterByWithdrawal: true), $today);
        $repayable = $loans->filter(fn (object $loan): bool => $this->isRepayable($loan));

        $par = function (Collection $items): array {
            $open = $items->filter(fn (object $loan): bool => $this->isRepayable($loan));
            $portfolio = round((float) $open->sum('out_principal'), 2);
            $row = [
                'label' => $items->first()->group_label ?? 'TOTAL',
                'loans' => $open->count(),
                'portfolio' => $portfolio,
                'loans_in_arrears' => $open->where('dpd', '>', 0)->count(),
                'arrears_amount' => round((float) $open->sum('arrears'), 2),
                'default_rate' => $this->percent($items->whereIn('status', LoanStatus::values(LoanStatus::Default, LoanStatus::WrittenOff))->count(), $items->count()),
            ];
            foreach (self::PAR_DAYS as $days) {
                $amount = round((float) $open->where('dpd', '>=', $days)->sum('out_principal'), 2);
                $row["par{$days}"] = $amount;
                $row["par{$days}_rate"] = $this->percent($amount, $portfolio);
            }

            return $row;
        };
        $grouped = fn (string $key, string $label): array => $loans->groupBy($key)
            ->map(fn (Collection $items): array => $par($items->map(fn (object $loan): object => (object) ((array) $loan + ['group_label' => $loan->{$label} ?? 'Not set']))))
            ->sortByDesc('portfolio')->values()->all();

        return [
            'summary' => $par($loans),
            'by_branch' => $grouped('branch_id', 'branch_name'),
            'by_officer' => $grouped('employee_id', 'officer_name'),
            'rows' => $repayable->where('dpd', '>', 0)->sortByDesc('dpd')->map(fn (object $loan): array => [
                'id' => (int) $loan->id,
                'loan_number' => $loan->loan_number,
                'customer_id' => (int) $loan->customer_id,
                'customer' => $loan->customer_name,
                'phone' => $loan->phone,
                'branch' => $loan->branch_name,
                'officer' => $loan->officer_name,
                'status' => LoanStatus::from($loan->status)->label(),
                'status_badge' => LoanStatus::from($loan->status)->badge(),
                'outstanding_principal' => (float) $loan->out_principal,
                'outstanding_total' => (float) $loan->out_total,
                'arrears' => (float) $loan->arrears,
                'oldest_due' => $loan->oldest_due,
                'dpd' => (int) $loan->dpd,
                'bucket' => InstalmentBehaviour::bucket((int) $loan->dpd),
            ])->values()->all(),
        ];
    }

    /**
     * Recovery Report: amount recovered from defaults and write-offs, recovery efficiency per branch.
     *
     * @return array<string, mixed>
     */
    public function recovery(ReportScope $scope): array
    {
        $loans = $this->loanDataset($scope, filterByWithdrawal: false);
        $defaulted = LoanStatus::values(LoanStatus::Default, LoanStatus::Closed, LoanStatus::WrittenOff);

        $recoveries = $scope->between($scope->apply(DB::table('loan_transactions')->join('loans', 'loans.id', '=', 'loan_transactions.loan_id'), 'loans'), 'loan_transactions.transaction_date')
            ->join('branches', 'branches.id', '=', 'loans.branch_id')
            ->join('customers', 'customers.id', '=', 'loans.customer_id')
            ->where('loan_transactions.type', 'deposit')
            ->whereNull('loan_transactions.reversed_at')
            ->whereIn('loans.status', $defaulted)
            ->whereNotNull('loans.end_date')
            ->whereColumn('loan_transactions.transaction_date', '>', 'loans.end_date')
            ->where(fn ($query) => $query->whereIn('loans.status', LoanStatus::values(LoanStatus::Default, LoanStatus::WrittenOff))
                ->orWhereRaw('DATEDIFF(loan_transactions.transaction_date, loans.end_date) > 30'))
            ->orderBy('loan_transactions.transaction_date')
            ->get([
                'loan_transactions.id', 'loan_transactions.transaction_date', 'loan_transactions.amount', 'loan_transactions.principal',
                'loan_transactions.penalty', 'loan_transactions.interest', 'loans.id as loan_id', 'loans.loan_number', 'loans.end_date', 'loans.status',
                'loans.branch_id', 'branches.name as branch_name', 'customers.id as customer_id',
                DB::raw("TRIM(CONCAT_WS(' ', customers.first_name, customers.middle_name, customers.last_name)) as customer_name"),
            ]);

        $writeOffs = $scope->apply(DB::table('write_offs')->join('loans', 'loans.id', '=', 'write_offs.loan_id'), 'loans')
            ->selectRaw('write_offs.amount, '.WriteOff::recoveredSql().' AS recovered_amount, loans.branch_id')
            ->get();

        $afterWriteOff = $scope->between($scope->apply(DB::table('loan_recoveries')->join('loans', 'loans.id', '=', 'loan_recoveries.loan_id'), 'loans'), 'loan_recoveries.recovered_on')
            ->join('branches', 'branches.id', '=', 'loans.branch_id')
            ->join('customers', 'customers.id', '=', 'loans.customer_id')
            ->whereNull('loan_recoveries.reversed_at')
            ->orderBy('loan_recoveries.recovered_on')
            ->get([
                'loan_recoveries.id', 'loan_recoveries.recovered_on', 'loan_recoveries.amount', 'loans.id as loan_id', 'loans.loan_number', 'loans.end_date', 'loans.status', 'loans.branch_id',
                'branches.name as branch_name', 'customers.id as customer_id',
                DB::raw("TRIM(CONCAT_WS(' ', customers.first_name, customers.middle_name, customers.last_name)) as customer_name"),
            ]);

        $summarise = function (Collection $branchLoans, Collection $branchRecoveries, Collection $branchWriteOffs, string $label): array {
            $inDefault = $branchLoans->where('status', LoanStatus::Default->value);
            $defaultBalance = round((float) $inDefault->sum('out_total'), 2);
            $writtenOff = round((float) $branchWriteOffs->sum('amount'), 2);
            $recoveredWriteOff = round((float) $branchWriteOffs->sum('recovered_amount'), 2);
            $recoveredDefault = round((float) $branchRecoveries->sum('amount'), 2);
            $total = round($recoveredDefault + $recoveredWriteOff, 2);

            return [
                'label' => $label,
                'default_loans' => $inDefault->count(),
                'default_balance' => $defaultBalance,
                'written_off' => $writtenOff,
                'recovered_default' => $recoveredDefault,
                'recovered_write_off' => $recoveredWriteOff,
                'unrecovered_write_off' => max(0.0, round($writtenOff - $recoveredWriteOff, 2)),
                'total_recovered' => $total,
                'efficiency' => $this->percent($total, $total + $defaultBalance + max(0, $writtenOff - $recoveredWriteOff)),
            ];
        };

        $branchIds = $loans->pluck('branch_id')->merge($recoveries->pluck('branch_id'))->unique()->sort()->values();

        return [
            'summary' => $summarise($loans, $recoveries, $writeOffs, 'TOTAL'),
            'by_branch' => $branchIds->map(fn (int $branchId): array => $summarise(
                $loans->where('branch_id', $branchId),
                $recoveries->where('branch_id', $branchId),
                $writeOffs->where('branch_id', $branchId),
                (string) ($loans->firstWhere('branch_id', $branchId)->branch_name ?? $recoveries->firstWhere('branch_id', $branchId)->branch_name ?? ''),
            ))->values()->all(),
            'rows' => $recoveries->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'date' => substr((string) $row->transaction_date, 0, 10),
                'loan_id' => (int) $row->loan_id,
                'loan_number' => $row->loan_number,
                'customer_id' => (int) $row->customer_id,
                'customer' => $row->customer_name,
                'branch' => $row->branch_name,
                'end_date' => substr((string) $row->end_date, 0, 10),
                'days_after_end' => (int) CarbonImmutable::parse(substr((string) $row->end_date, 0, 10))->diffInDays(CarbonImmutable::parse(substr((string) $row->transaction_date, 0, 10))),
                'amount' => (float) $row->amount,
                'principal' => (float) $row->principal,
                'penalty' => (float) $row->penalty,
                'interest' => (float) $row->interest,
                'status' => LoanStatus::from($row->status)->label(),
                'source' => 'repayment',
            ])->merge($afterWriteOff->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'date' => substr((string) $row->recovered_on, 0, 10),
                'loan_id' => (int) $row->loan_id,
                'loan_number' => $row->loan_number,
                'customer_id' => (int) $row->customer_id,
                'customer' => $row->customer_name,
                'branch' => $row->branch_name,
                'end_date' => $row->end_date !== null ? substr((string) $row->end_date, 0, 10) : null,
                'days_after_end' => $row->end_date !== null ? (int) CarbonImmutable::parse(substr((string) $row->end_date, 0, 10))->diffInDays(CarbonImmutable::parse(substr((string) $row->recovered_on, 0, 10))) : null,
                'amount' => (float) $row->amount,
                'principal' => 0.0,
                'penalty' => 0.0,
                'interest' => (float) $row->amount,
                'status' => LoanStatus::from($row->status)->label(),
                'source' => 'write_off_recovery',
            ]))->sortBy('date')->values()->all(),
        ];
    }

    /**
     * Repayment Behaviour: days delayed per instalment, DPD buckets, average behaviour and rating A–D per customer.
     *
     * @return array<string, mixed>
     */
    public function behaviour(ReportScope $scope, CarbonImmutable $today): array
    {
        $loans = $this->loanDataset($scope, filterByWithdrawal: false)->keyBy('id');
        $instalments = $this->dueInstalments($loans, $today, $scope);
        $totalAmount = (float) $instalments->sum('amount');

        $customers = $instalments->groupBy(fn (array $row): int => (int) $loans[$row['loan_id']]->customer_id)
            ->map(function (Collection $rows, int $customerId) use ($loans): array {
                $customerLoans = $loans->where('customer_id', $customerId);
                $late = $rows->where('delay_days', '>', 0)->count();
                $average = round((float) $rows->avg('delay_days'), 1);
                $maxDelay = (int) $rows->max('delay_days');
                $defaults = $customerLoans->whereIn('status', LoanStatus::values(LoanStatus::Default, LoanStatus::WrittenOff))->count();
                $rating = match (true) {
                    $defaults > 0 || $maxDelay > 30 => 'D',
                    $maxDelay > 7 => 'C',
                    $maxDelay > 0 => 'B',
                    default => 'A',
                };
                $first = $customerLoans->first();

                return [
                    'customer_id' => $customerId,
                    'customer' => $first->customer_name,
                    'phone' => $first->phone,
                    'branch' => $first->branch_name,
                    'total_loans' => $customerLoans->count(),
                    'instalments' => $rows->count(),
                    'avg_delay_days' => $average,
                    'max_delay_days' => $maxDelay,
                    'on_time_rate' => $this->percent($rows->count() - $late, $rows->count()),
                    'late_rate' => $this->percent($late, $rows->count()),
                    'default_history' => $defaults,
                    'rating' => $rating,
                    'pattern' => match (true) {
                        $rating === 'D' => 'Chronic defaulter',
                        $late === 0 && $average < 0 => 'Early payer',
                        $late === 0 => 'On-time payer',
                        default => 'Late payer',
                    },
                ];
            })
            ->sortBy('customer')
            ->values();

        return [
            'buckets' => collect(InstalmentBehaviour::BUCKETS)->map(fn (string $bucket): array => [
                'bucket' => $bucket,
                'label' => InstalmentBehaviour::BUCKET_LABELS[$bucket],
                'instalments' => $instalments->where('bucket', $bucket)->count(),
                'amount' => round((float) $instalments->where('bucket', $bucket)->sum('amount'), 2),
                'share' => $this->percent((float) $instalments->where('bucket', $bucket)->sum('amount'), $totalAmount),
            ])->all(),
            'ratings' => collect(['A', 'B', 'C', 'D'])->mapWithKeys(fn (string $rating): array => [$rating => $customers->where('rating', $rating)->count()])->all(),
            'patterns' => collect(['Early payer', 'On-time payer', 'Late payer', 'Chronic defaulter'])->map(fn (string $pattern): array => [
                'pattern' => $pattern,
                'customers' => $customers->where('pattern', $pattern)->count(),
            ])->all(),
            'summary' => [
                'instalments' => $instalments->count(),
                'avg_delay_days' => round((float) $instalments->avg('delay_days'), 1),
                'on_time_rate' => $this->percent($instalments->where('delay_days', '<=', 0)->count(), $instalments->count()),
            ],
            'customers' => $customers->all(),
            'rows' => $instalments->sortBy([['due_date', 'asc'], ['loan_id', 'asc']])->map(fn (array $row): array => [
                'id' => $row['id'],
                'loan_id' => $row['loan_id'],
                'loan_number' => $loans[$row['loan_id']]->loan_number,
                'customer_id' => (int) $loans[$row['loan_id']]->customer_id,
                'customer' => $loans[$row['loan_id']]->customer_name,
                'branch' => $loans[$row['loan_id']]->branch_name,
                'due_date' => $row['due_date'],
                'paid_date' => $row['paid_date'],
                'amount' => $row['amount'],
                'paid_amount' => $row['paid_amount'],
                'delay_days' => $row['delay_days'],
                'bucket' => $row['bucket'],
                'bucket_label' => InstalmentBehaviour::BUCKET_LABELS[$row['bucket']],
            ])->values()->all(),
        ];
    }

    /**
     * Customer Segmentation: per gender, age group, occupation, customer type, region, branch and loan size.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function segmentation(ReportScope $scope, CarbonImmutable $today): array
    {
        $customers = $this->customerDataset($scope, $today);
        $loans = $this->loanDataset($scope, filterByWithdrawal: true);
        $instalments = $this->dueInstalments($loans->keyBy('id'), $today)->groupBy('loan_id');

        return collect(self::SEGMENT_DIMENSIONS)
            ->mapWithKeys(fn (string $dimension): array => [$dimension => $this->segmentRows($dimension, $customers, $loans, $instalments, $today)])
            ->all();
    }

    /**
     * Age Analysis: customers, loan volume, repayment rate, default rate and average delay per age group.
     *
     * @return list<array<string, mixed>>
     */
    public function ageAnalysis(ReportScope $scope, CarbonImmutable $today): array
    {
        $loans = $this->loanDataset($scope, filterByWithdrawal: true);

        return $this->segmentRows('age', $this->customerDataset($scope, $today), $loans, $this->dueInstalments($loans->keyBy('id'), $today)->groupBy('loan_id'), $today);
    }

    /**
     * @param  Collection<int, object>  $customers
     * @param  Collection<int, object>  $loans
     * @param  Collection<int|string, Collection<int, array<string, mixed>>>  $instalments
     * @return list<array<string, mixed>>
     */
    private function segmentRows(string $dimension, Collection $customers, Collection $loans, Collection $instalments, CarbonImmutable $today): array
    {
        $byCustomer = $customers->keyBy('id');
        $loanSegment = fn (object $loan): string => $dimension === 'loan_size'
            ? $this->loanSizeBand((float) $loan->amount_approved)
            : ($byCustomer->get($loan->customer_id)?->segments[$dimension] ?? $this->customerSegments($loan, $today)[$dimension]);

        $loanGroups = $loans->groupBy($loanSegment);
        $customerGroups = $dimension === 'loan_size' ? collect() : $customers->groupBy(fn (object $customer): string => $customer->segments[$dimension]);
        $labels = $dimension === 'age'
            ? collect(self::AGE_BANDS)->merge($customerGroups->keys()->merge($loanGroups->keys())->diff(self::AGE_BANDS)->sort())
            : ($dimension === 'loan_size'
                ? collect(array_keys(self::LOAN_SIZE_BANDS))->filter(fn (string $band): bool => $loanGroups->has($band))
                : $customerGroups->keys()->merge($loanGroups->keys())->unique()->sort());

        return $labels->unique()->values()->map(function (string $label) use ($dimension, $loanGroups, $customerGroups, $instalments): array {
            $segmentLoans = $loanGroups->get($label, collect());
            $due = $segmentLoans->flatMap(fn (object $loan): Collection => $instalments->get($loan->id, collect()));
            $dueAmount = (float) $due->sum('amount');

            return [
                'segment' => $label,
                'customers' => $dimension === 'loan_size' ? $segmentLoans->pluck('customer_id')->unique()->count() : $customerGroups->get($label, collect())->count(),
                'loans' => $segmentLoans->count(),
                'disbursed' => round((float) $segmentLoans->sum('amount_approved'), 2),
                'collected' => round((float) $segmentLoans->sum('paid_total'), 2),
                'repayment_rate' => $this->percent((float) $due->sum(fn (array $row): float => min($row['paid_amount'], $row['amount'])), $dueAmount),
                'default_rate' => $this->percent($segmentLoans->whereIn('status', LoanStatus::values(LoanStatus::Default, LoanStatus::WrittenOff))->count(), $segmentLoans->count()),
                'avg_delay_days' => round((float) $due->avg('delay_days'), 1),
                'profit' => round((float) $segmentLoans->sum(fn (object $loan): float => (float) $loan->paid_interest + (float) $loan->paid_penalty + ($loan->fee_deduct ? (float) $loan->loan_fee : 0)), 2),
            ];
        })->all();
    }

    /**
     * Loan size bands (inferred; the Documents only name "Loan Size").
     *
     * @var array<string, array{0: float, 1: float}>
     */
    public const LOAN_SIZE_BANDS = [
        'Below 500,000' => [0, 500000],
        '500,000 – 999,999' => [500000, 1000000],
        '1,000,000 – 4,999,999' => [1000000, 5000000],
        '5,000,000+' => [5000000, PHP_FLOAT_MAX],
    ];

    private function loanSizeBand(float $amount): string
    {
        foreach (self::LOAN_SIZE_BANDS as $label => [$from, $to]) {
            if ($amount >= $from && $amount < $to) {
                return $label;
            }
        }

        return 'Below 500,000';
    }

    public static function ageBand(?int $age): string
    {
        return match (true) {
            $age === null => 'Unknown',
            $age < 18 => 'Under 18',
            $age <= 25 => '18–25',
            $age <= 35 => '26–35',
            $age <= 45 => '36–45',
            $age <= 60 => '46–60',
            default => '60+',
        };
    }

    /**
     * @return array<string, string>
     */
    private function customerSegments(object $row, CarbonImmutable $today): array
    {
        $age = $row->date_of_birth ? (int) CarbonImmutable::parse($row->date_of_birth)->diffInYears($today) : ($row->customer_age !== null ? (int) $row->customer_age : null);

        return [
            'category' => $row->category_name ?? 'No customer type',
            'gender' => $row->gender ? ucfirst(strtolower($row->gender)) : 'Unknown',
            'age' => self::ageBand($age),
            'occupation' => $row->business_type ?: ($row->work_status ?: 'Unknown'),
            'region' => $row->residence_region ?: ($row->region_name ?: 'Unknown'),
            'branch' => $row->branch_name ?? 'Unknown',
        ];
    }

    /**
     * Scoped customers with the attributes used for segmentation.
     *
     * @return Collection<int, object>
     */
    private function customerDataset(ReportScope $scope, CarbonImmutable $today): Collection
    {
        return $this->withCustomerAttributes($scope->apply(DB::table('customers'), 'customers'))
            ->addSelect('customers.id')
            ->get()
            ->map(function (object $customer) use ($today): object {
                $customer->segments = $this->customerSegments($customer, $today);

                return $customer;
            });
    }

    /**
     * Cashed-out loans in scope with balances and grouping attributes.
     *
     * @return Collection<int, object>
     */
    private function loanDataset(ReportScope $scope, bool $filterByWithdrawal): Collection
    {
        $query = LoanBalances::join($scope->apply(DB::table('loans'), 'loans'))
            ->whereIn('loans.status', LoanStatus::values(...LoanStatus::disbursed()))
            ->leftJoin('loan_categories as product', 'product.id', '=', 'loans.loan_category_id')
            ->leftJoin('employees as officer', 'officer.id', '=', 'loans.employee_id')
            ->addSelect([
                'product.name as product_name',
                DB::raw("TRIM(CONCAT_WS(' ', officer.first_name, officer.middle_name, officer.last_name)) as officer_name"),
                DB::raw("TRIM(CONCAT_WS(' ', customers.first_name, customers.middle_name, customers.last_name)) as customer_name"),
                'customers.phone',
                'customers.customer_category_id',
            ]);

        if ($filterByWithdrawal) {
            $scope->between($query, 'loans.withdrawn_at');
        }

        return $this->withCustomerAttributes($query->leftJoin('customers', 'customers.id', '=', 'loans.customer_id'), 'loans.branch_id')
            ->orderBy('loans.id')
            ->get();
    }

    /**
     * @template TBuilder of \Illuminate\Database\Query\Builder
     *
     * @param  TBuilder  $query  a query already joined (or based) on `customers`
     * @return TBuilder
     */
    private function withCustomerAttributes($query, string $branchColumn = 'customers.branch_id')
    {
        $residences = DB::table('customer_residences')->groupBy('customer_id')->selectRaw('customer_id, MAX(region_name) as region_name');

        return $query
            ->leftJoin('branches', 'branches.id', '=', $branchColumn)
            ->leftJoin('customer_categories as customer_category', 'customer_category.id', '=', 'customers.customer_category_id')
            ->leftJoin('regions', 'regions.id', '=', 'customers.region_id')
            ->leftJoinSub($residences, 'residence', 'residence.customer_id', '=', 'customers.id')
            ->addSelect([
                'branches.name as branch_name',
                'customer_category.name as category_name',
                'regions.name as region_name',
                'residence.region_name as residence_region',
                'customers.gender',
                'customers.date_of_birth',
                'customers.age as customer_age',
                'customers.business_type',
                'customers.work_status',
            ]);
    }

    /**
     * Adds `arrears` (unpaid amount of instalments due before today), `oldest_due` and `dpd` to each loan.
     *
     * @param  Collection<int, object>  $loans
     * @return Collection<int, object>
     */
    private function withArrears(Collection $loans, CarbonImmutable $today): Collection
    {
        $arrears = DB::table('loan_schedules')
            ->whereIn('loan_id', $loans->pluck('id')->all() ?: [0])
            ->where('due_date', '<', $today->toDateString())
            ->whereColumn('paid_amount', '<', 'amount')
            ->groupBy('loan_id')
            ->selectRaw('loan_id, SUM(amount - paid_amount) as arrears, MIN(due_date) as oldest_due')
            ->get()
            ->keyBy('loan_id');

        return $loans->map(function (object $loan) use ($arrears, $today): object {
            $row = $arrears->get($loan->id);
            $loan->arrears = round((float) ($row->arrears ?? 0), 2);
            $loan->oldest_due = $row ? substr((string) $row->oldest_due, 0, 10) : null;
            $loan->dpd = $row && $this->isRepayable($loan) ? (int) CarbonImmutable::parse($loan->oldest_due)->diffInDays($today) : 0;

            return $loan;
        });
    }

    /**
     * Instalments already due (paid or past their due date) of the given loans, optionally limited to the scope dates.
     *
     * @param  Collection<int, object>  $loans  keyed by id
     * @return Collection<int, array<string, mixed>>
     */
    private function dueInstalments(Collection $loans, CarbonImmutable $today, ?ReportScope $scope = null): Collection
    {
        return $this->behaviour->instalments($loans->keys()->map(fn ($id): int => (int) $id)->all(), $today)
            ->filter(fn (array $row): bool => $row['is_due'])
            ->filter(fn (array $row): bool => $scope === null
                || (($scope->from === null || $row['due_date'] >= $scope->from->toDateString()) && ($scope->to === null || $row['due_date'] <= $scope->to->toDateString())))
            ->values();
    }

    private function periodKey(string $date, string $period): string
    {
        $day = CarbonImmutable::parse($date);

        return match ($period) {
            'daily' => $day->toDateString(),
            'weekly' => $day->startOfWeek()->toDateString(),
            default => $day->format('Y-m'),
        };
    }

    private function isRepayable(object $loan): bool
    {
        return in_array($loan->status, LoanStatus::values(...LoanStatus::repayable()), true);
    }

    private function percent(float|int $part, float|int $whole): float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : 0.0;
    }
}
