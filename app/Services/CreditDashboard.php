<?php

namespace App\Services;

use App\Enums\LoanStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Services\Reports\PortfolioReports;
use App\Services\Reports\ReportScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Credit Department dashboard (role Credit Officer): the loan applications of a month and a year, the approval pipeline,
 * today's collections and the quality of the portfolio per loan officer, branch and customer type — for the whole company or
 * one branch. Every figure comes from the records:
 *
 *  - applications: loans applied for in the period (any status). Each application is counted by what happened to it:
 *    approved = passed the branch manager and not rejected or cancelled since; rejected = status REJECTED. The cards,
 *    the trend chart and the officer / customer type tables all count applications this way, so they agree with each other;
 *  - active portfolio: repayable loans (active, overdue, default) now;
 *  - collection vs outstanding: per month of the year, the instalments due on disbursed loans, the repayments received
 *    (reversals excluded) and what is still unpaid on the instalments already due;
 *  - today: instalments due today, repayments received today and what is still unpaid on today's instalments;
 *  - payment mandate: the instalments due from 1 January to today, split into collected and unpaid;
 *  - pending approvals: applications in the pipeline grouped by the stage they wait at;
 *  - arrears % and default %: the Default & Arrears report (PAR 1 rate and default rate) per officer and branch, and the
 *    same default rate per customer type.
 */
final class CreditDashboard
{
    public function __construct(private readonly PortfolioReports $portfolio) {}

    /**
     * @param  list<int>|null  $branchIds  null = the whole company
     * @return array<string, mixed>
     */
    public function build(Company $company, Employee $viewer, CarbonImmutable $month, ?array $branchIds, CarbonImmutable $today): array
    {
        $from = $month->startOfMonth();
        $to = $month->endOfMonth();
        $yearFrom = $month->startOfYear();
        $yearTo = $month->endOfYear();
        $scope = new ReportScope((int) $company->id, $branchIds);

        $yearApplications = $this->applications($company, $branchIds, $yearFrom, $yearTo);
        $monthApplications = $yearApplications->filter(fn (object $loan): bool => substr((string) $loan->applied_on, 0, 7) === $from->format('Y-m'));
        $arrears = $this->portfolio->arrears($scope, $today);
        $repayable = $this->scoped(DB::table('loans'), $company, $branchIds)->whereIn('loans.status', LoanStatus::values(...LoanStatus::repayable()));

        return [
            'month' => $from->format('Y-m'),
            'month_label' => $from->format('F Y'),
            'year' => (int) $from->format('Y'),
            'cards' => [
                'applications' => $monthApplications->count(),
                'applications_customers' => $monthApplications->pluck('customer_id')->unique()->count(),
                'approved' => $monthApplications->filter(fn (object $loan): bool => $this->isApproved($loan))->count(),
                'approved_customers' => $monthApplications->filter(fn (object $loan): bool => $this->isApproved($loan))->pluck('customer_id')->unique()->count(),
                'rejected' => $monthApplications->where('status', LoanStatus::Rejected->value)->count(),
                'rejected_customers' => $monthApplications->where('status', LoanStatus::Rejected->value)->pluck('customer_id')->unique()->count(),
                'active_loans' => (clone $repayable)->count(),
                'active_customers' => (clone $repayable)->distinct()->count('loans.customer_id'),
            ],
            'applications_trend' => $this->applicationsTrend($yearApplications, $yearFrom),
            'collection_trend' => $this->collectionTrend($company, $branchIds, $yearFrom, $yearTo, $today),
            'approvals' => $this->approvals($company, $branchIds, $viewer),
            'today' => $this->today($company, $branchIds, $today),
            'payment_mandate' => $this->paymentMandate($company, $branchIds, $yearFrom, $today->min($yearTo)),
            'officers' => $this->performance($monthApplications, 'employee_id', 'officer_name', collect($arrears['by_officer'])),
            'branches' => collect($arrears['by_branch'])->map(fn (array $row): array => [
                'label' => $row['label'],
                'active_loans' => $row['loans'],
                'arrears_percent' => $row['par1_rate'],
                'default_percent' => $row['default_rate'],
            ])->values()->all(),
            'customer_types' => $this->customerTypes($company, $branchIds, $monthApplications),
        ];
    }

    /**
     * Per customer type: the month's applications and approvals, and the default % of its disbursed loans (loans in
     * DEFAULT or written off out of every disbursed loan of the type, as the branch default % of the Default & Arrears report).
     *
     * @param  list<int>|null  $branchIds
     * @param  Collection<int, object>  $applications
     * @return list<array{label: string, applications: int, approved: int, default_percent: float}>
     */
    private function customerTypes(Company $company, ?array $branchIds, Collection $applications): array
    {
        $disbursed = $this->scoped(DB::table('loans'), $company, $branchIds)
            ->leftJoin('customers', 'customers.id', '=', 'loans.customer_id')
            ->leftJoin('customer_categories as customer_type', 'customer_type.id', '=', 'customers.customer_category_id')
            ->whereIn('loans.status', LoanStatus::values(...LoanStatus::disbursed()))
            ->groupBy('customers.customer_category_id', 'customer_type.name')
            ->selectRaw('customers.customer_category_id, customer_type.name as customer_type_name, COUNT(*) as loans')
            ->selectRaw('SUM(CASE WHEN loans.status IN (?, ?) THEN 1 ELSE 0 END) as defaulted', LoanStatus::values(LoanStatus::Default, LoanStatus::WrittenOff))
            ->get()
            ->keyBy(fn (object $row): string => (string) ($row->customer_category_id ?? 0));
        $byType = $applications->groupBy(fn (object $loan): string => (string) ($loan->customer_category_id ?? 0));

        return $byType->keys()->merge($disbursed->keys())->unique()
            ->map(function (string $key) use ($byType, $disbursed): array {
                $items = $byType->get($key, collect());
                $loans = $disbursed->get($key);

                return [
                    'label' => ($items->first()->customer_type_name ?? $loans->customer_type_name ?? null) ?: 'Not set',
                    'applications' => $items->count(),
                    'approved' => $items->filter(fn (object $loan): bool => $this->isApproved($loan))->count(),
                    'default_percent' => $this->percent((float) ($loans->defaulted ?? 0), (float) ($loans->loans ?? 0)),
                ];
            })
            ->sortBy([['applications', 'desc'], ['label', 'asc']])
            ->values()
            ->all();
    }

    /**
     * Loans applied for between two dates, with their officer and product.
     *
     * @param  list<int>|null  $branchIds
     * @return Collection<int, object>
     */
    private function applications(Company $company, ?array $branchIds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->scoped(DB::table('loans'), $company, $branchIds)
            ->leftJoin('loan_categories as product', 'product.id', '=', 'loans.loan_category_id')
            ->leftJoin('employees as officer', 'officer.id', '=', 'loans.employee_id')
            ->leftJoin('customers', 'customers.id', '=', 'loans.customer_id')
            ->leftJoin('customer_categories as customer_type', 'customer_type.id', '=', 'customers.customer_category_id')
            ->whereBetween('loans.created_at', [$from->startOfDay(), $to->endOfDay()])
            ->get([
                'loans.id', 'loans.customer_id', 'loans.employee_id', 'loans.loan_category_id', 'loans.status', 'loans.approved_at',
                DB::raw('DATE(loans.created_at) as applied_on'),
                'product.name as product_name',
                'customers.customer_category_id',
                'customer_type.name as customer_type_name',
                DB::raw("TRIM(CONCAT_WS(' ', officer.first_name, officer.middle_name, officer.last_name)) as officer_name"),
            ]);
    }

    /**
     * Passed the branch manager and not ended by rejection or cancellation since.
     */
    private function isApproved(object $loan): bool
    {
        return $loan->approved_at !== null && ! in_array($loan->status, LoanStatus::values(LoanStatus::Rejected, LoanStatus::Cancelled), true);
    }

    /**
     * Applied / approved / rejected applications per month of the year.
     *
     * @param  Collection<int, object>  $applications
     * @return list<array{month: string, label: string, applied: int, approved: int, rejected: int}>
     */
    private function applicationsTrend(Collection $applications, CarbonImmutable $yearFrom): array
    {
        $byMonth = $applications->groupBy(fn (object $loan): string => substr((string) $loan->applied_on, 0, 7));

        return collect(range(0, 11))->map(function (int $offset) use ($byMonth, $yearFrom): array {
            $month = $yearFrom->addMonthsNoOverflow($offset);
            $items = $byMonth->get($month->format('Y-m'), collect());

            return [
                'month' => $month->format('Y-m'),
                'label' => $month->format('M'),
                'applied' => $items->count(),
                'approved' => $items->filter(fn (object $loan): bool => $this->isApproved($loan))->count(),
                'rejected' => $items->where('status', LoanStatus::Rejected->value)->count(),
            ];
        })->all();
    }

    /**
     * Per month of the year: instalments due, repayments received and what is still unpaid on the instalments already due.
     *
     * @param  list<int>|null  $branchIds
     * @return list<array{month: string, label: string, expected: float, collected: float, unpaid: float}>
     */
    private function collectionTrend(Company $company, ?array $branchIds, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $today): array
    {
        $due = $this->instalments($company, $branchIds, $from, $to)
            ->get(['loan_schedules.due_date', 'loan_schedules.amount', 'loan_schedules.paid_amount'])
            ->groupBy(fn (object $row): string => substr((string) $row->due_date, 0, 7));
        $paid = $this->repayments($company, $branchIds)
            ->whereBetween('loan_transactions.transaction_date', [$from->toDateString(), $to->toDateString()])
            ->get(['loan_transactions.transaction_date', 'loan_transactions.amount'])
            ->groupBy(fn (object $row): string => substr((string) $row->transaction_date, 0, 7));

        return collect(range(0, 11))->map(function (int $offset) use ($from, $due, $paid, $today): array {
            $month = $from->addMonthsNoOverflow($offset);
            $instalments = $due->get($month->format('Y-m'), collect());

            return [
                'month' => $month->format('Y-m'),
                'label' => $month->format('M'),
                'expected' => round((float) $instalments->sum('amount'), 2),
                'collected' => round((float) $paid->get($month->format('Y-m'), collect())->sum('amount'), 2),
                'unpaid' => round((float) $instalments->filter(fn (object $row): bool => (string) $row->due_date <= $today->toDateString())->sum(fn (object $row): float => $this->unpaid($row)), 2),
            ];
        })->all();
    }

    /**
     * Today's expected collection, what was collected and what is still unpaid, each with its number of loans.
     *
     * @param  list<int>|null  $branchIds
     * @return array{expected: float, expected_accounts: int, collected: float, collected_accounts: int, unpaid: float, unpaid_accounts: int}
     */
    private function today(Company $company, ?array $branchIds, CarbonImmutable $today): array
    {
        $due = $this->instalments($company, $branchIds, $today, $today)->get(['loan_schedules.loan_id', 'loan_schedules.amount', 'loan_schedules.paid_amount']);
        $paid = $this->repayments($company, $branchIds)->whereDate('loan_transactions.transaction_date', $today->toDateString())->get(['loan_transactions.loan_id', 'loan_transactions.amount']);
        $unpaid = $due->filter(fn (object $row): bool => $this->unpaid($row) > 0);

        return [
            'expected' => round((float) $due->sum('amount'), 2),
            'expected_accounts' => $due->pluck('loan_id')->unique()->count(),
            'collected' => round((float) $paid->sum('amount'), 2),
            'collected_accounts' => $paid->pluck('loan_id')->filter()->unique()->count(),
            'unpaid' => round((float) $unpaid->sum(fn (object $row): float => $this->unpaid($row)), 2),
            'unpaid_accounts' => $unpaid->pluck('loan_id')->unique()->count(),
        ];
    }

    /**
     * Instalments due from the start of the year to today: what was collected on them and what is unpaid.
     *
     * @param  list<int>|null  $branchIds
     * @return array{total: float, collected: float, unpaid: float, collected_percent: float, unpaid_percent: float}
     */
    private function paymentMandate(Company $company, ?array $branchIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $due = $to->lt($from) ? collect() : $this->instalments($company, $branchIds, $from, $to)->get(['loan_schedules.amount', 'loan_schedules.paid_amount']);
        $total = round((float) $due->sum('amount'), 2);
        $unpaid = round((float) $due->sum(fn (object $row): float => $this->unpaid($row)), 2);
        $collected = round($total - $unpaid, 2);

        return [
            'total' => $total,
            'collected' => $collected,
            'unpaid' => $unpaid,
            'collected_percent' => $this->percent($collected, $total),
            'unpaid_percent' => $this->percent($unpaid, $total),
        ];
    }

    /**
     * Applications waiting in the pipeline, one line per stage, with the page that handles them (null when the viewer may
     * not open it).
     *
     * @param  list<int>|null  $branchIds
     * @return list<array{key: string, label: string, count: int, status: string, tone: string, link: string|null}>
     */
    private function approvals(Company $company, ?array $branchIds, Employee $viewer): array
    {
        $counts = $this->scoped(DB::table('loans'), $company, $branchIds)
            ->whereIn('loans.status', LoanStatus::values(...LoanStatus::inPipeline()))
            ->groupBy('loans.status')
            ->selectRaw('loans.status, COUNT(*) as total')
            ->pluck('total', 'status');
        $count = fn (LoanStatus ...$statuses): int => (int) collect(LoanStatus::values(...$statuses))->sum(fn (string $status): int => (int) ($counts[$status] ?? 0));

        // A top-up: an application from a customer who still owes on a loan; the new loan settles the old one when paid out.
        $topUps = $this->scoped(DB::table('loans'), $company, $branchIds)
            ->whereIn('loans.status', LoanStatus::values(...LoanStatus::inPipeline()))
            ->whereExists(fn (Builder $query) => $query->from('loans as open_loan')
                ->whereColumn('open_loan.customer_id', 'loans.customer_id')
                ->whereColumn('open_loan.id', '!=', 'loans.id')
                ->whereIn('open_loan.status', LoanStatus::values(...LoanStatus::repayable())))
            ->count();

        $disbursementPage = $viewer->can('loans.prepare_disbursement') || $viewer->can('loans.disburse') ? '/loans/disbursement' : null;

        return [
            ['key' => 'applications', 'label' => 'Loan Applications', 'count' => $count(LoanStatus::PendingManagerApproval), 'status' => 'Pending Approval', 'tone' => 'warning', 'link' => '/loans/pending'],
            ['key' => 'disbursements', 'label' => 'Loan Disbursements', 'count' => $count(LoanStatus::PendingFinance, LoanStatus::AwaitingDisbursement), 'status' => 'Awaiting Finance', 'tone' => 'danger', 'link' => $disbursementPage],
            ['key' => 'credit_review', 'label' => 'Credit Review', 'count' => $count(LoanStatus::PendingCreditReview), 'status' => 'Pending Review', 'tone' => 'warning', 'link' => '/loans/credit-review'],
            ['key' => 'top_ups', 'label' => 'Top-Up / Offset Requests', 'count' => $topUps, 'status' => 'Pending Assessment', 'tone' => 'warning', 'link' => '/loans/credit-assessments'],
            ['key' => 'mandates', 'label' => 'E-Mandate', 'count' => $count(LoanStatus::MandatePendingOtp, LoanStatus::MandateFailed), 'status' => 'Awaiting Mandate', 'tone' => 'warning', 'link' => '/loans/pending'],
            ['key' => 'disbursement_issues', 'label' => 'Disbursement Issues', 'count' => $count(LoanStatus::DisbursementFailed, LoanStatus::Escalated, LoanStatus::DisbursementSuspense), 'status' => 'Requires Action', 'tone' => 'danger', 'link' => $disbursementPage],
            ['key' => 'returned', 'label' => 'Returned Applications', 'count' => $count(LoanStatus::Returned), 'status' => 'Requires Action', 'tone' => 'danger', 'link' => '/loans/pending'],
        ];
    }

    /**
     * Applications, approvals and rejection rate per officer or product; with the arrears report's rows, also the officer's
     * arrears % (PAR 1 rate of their portfolio).
     *
     * @param  Collection<int, object>  $applications
     * @param  Collection<int, array<string, mixed>>|null  $arrears
     * @return list<array<string, mixed>>
     */
    private function performance(Collection $applications, string $key, string $label, ?Collection $arrears = null): array
    {
        $arrearsByLabel = $arrears?->keyBy('label');

        return $applications->groupBy(fn (object $loan): string => (string) ($loan->{$key} ?? 0))
            ->map(function (Collection $items) use ($label, $arrearsByLabel): array {
                $name = $items->first()->{$label} ?: 'Not set';
                $rejected = $items->where('status', LoanStatus::Rejected->value)->count();
                $row = [
                    'label' => $name,
                    'applications' => $items->count(),
                    'approved' => $items->filter(fn (object $loan): bool => $this->isApproved($loan))->count(),
                    'rejection_percent' => $this->percent($rejected, $items->count()),
                ];
                if ($arrearsByLabel !== null) {
                    $row['arrears_percent'] = (float) ($arrearsByLabel->get($name)['par1_rate'] ?? 0);
                }

                return $row;
            })
            ->sortByDesc('applications')
            ->values()
            ->all();
    }

    /**
     * Instalments of disbursed loans due between two dates.
     *
     * @param  list<int>|null  $branchIds
     */
    private function instalments(Company $company, ?array $branchIds, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $this->scoped(DB::table('loan_schedules')->join('loans', 'loans.id', '=', 'loan_schedules.loan_id'), $company, $branchIds)
            ->whereIn('loans.status', LoanStatus::values(...LoanStatus::disbursed()))
            ->whereBetween('loan_schedules.due_date', [$from->toDateString(), $to->toDateString()]);
    }

    /**
     * Loan repayments, reversals excluded.
     *
     * @param  list<int>|null  $branchIds
     */
    private function repayments(Company $company, ?array $branchIds): Builder
    {
        return DB::table('loan_transactions')
            ->where('loan_transactions.company_id', $company->id)
            ->when($branchIds !== null, fn ($query) => $query->whereIn('loan_transactions.branch_id', $branchIds))
            ->where('loan_transactions.type', 'deposit')
            ->whereNull('loan_transactions.reversed_at');
    }

    /**
     * @param  list<int>|null  $branchIds
     */
    private function scoped(Builder $query, Company $company, ?array $branchIds): Builder
    {
        return $query->where('loans.company_id', $company->id)
            ->when($branchIds !== null, fn ($inner) => $inner->whereIn('loans.branch_id', $branchIds));
    }

    private function unpaid(object $instalment): float
    {
        return max(0.0, (float) $instalment->amount - (float) $instalment->paid_amount);
    }

    private function percent(float $part, float $whole): float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : 0.0;
    }
}
