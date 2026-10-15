<?php

namespace App\Services;

use App\Enums\LoanStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Services\Reports\PortfolioReports;
use App\Services\Reports\ReportScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Zone Manager dashboard: the Branch dashboard ({@see BranchDashboard}: loan book, petty cash, today's collection and
 * disbursement, approval queue, the month's pipeline) for every branch of the manager's zone — or one of them — and a
 * performance row per branch. Every figure comes from the records:
 *
 *  - active loans and portfolio: the branch's running loans (active, overdue, default) and their outstanding principal;
 *  - expected / actual collection: the month's instalments due on disbursed loans and the repayments received in the month
 *    (reversals excluded); collection % = actual / expected;
 *  - arrears % = PAR 1 rate (outstanding principal of loans with an instalment past due / portfolio) and default % = loans
 *    in DEFAULT or written off out of every disbursed loan — the Default & Arrears report's definitions.
 */
final class ZoneDashboard
{
    public function __construct(
        private readonly BranchDashboard $branch,
        private readonly PortfolioReports $portfolio,
    ) {}

    /**
     * @param  list<int>|null  $branchIds  the branches shown (null = the whole company)
     * @return array<string, mixed>
     */
    public function build(Company $company, Employee $viewer, CarbonImmutable $month, ?array $branchIds, CarbonImmutable $today): array
    {
        $branches = Branch::where('company_id', $company->id)->where('is_head_office', false)
            ->when($branchIds !== null, fn ($query) => $query->whereIn('id', $branchIds))
            ->orderBy('name')->get(['id', 'name']);
        $running = $this->portfolio->runningLoans(new ReportScope((int) $company->id, $branchIds), $today);

        return [
            ...$this->branch->build($company, $viewer, $month, $branchIds, $today),
            'zone' => $viewer->zone?->name,
            'branches' => $this->branches($company, $branches, $running, $month->startOfMonth(), $month->endOfMonth()),
        ];
    }

    /**
     * @param  Collection<int, Branch>  $branches
     * @param  Collection<int, object>  $running
     * @return array{rows: list<array<string, mixed>>, total: array<string, mixed>}
     */
    private function branches(Company $company, Collection $branches, Collection $running, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = $branches->pluck('id')->all();
        $expected = DB::table('loan_schedules')->join('loans', 'loans.id', '=', 'loan_schedules.loan_id')
            ->where('loans.company_id', $company->id)->whereIn('loans.branch_id', $ids ?: [0])
            ->whereIn('loans.status', LoanStatus::values(...LoanStatus::disbursed()))
            ->whereBetween('loan_schedules.due_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('loans.branch_id')->selectRaw('loans.branch_id, SUM(loan_schedules.amount) as total')->pluck('total', 'branch_id');
        $actual = $this->transactions($company, $ids, 'deposit')
            ->whereBetween('loan_transactions.transaction_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('loan_transactions.branch_id')->selectRaw('loan_transactions.branch_id, SUM(loan_transactions.amount) as total')->pluck('total', 'branch_id');
        $disbursed = DB::table('loans')->where('company_id', $company->id)->whereIn('branch_id', $ids ?: [0])
            ->whereIn('status', LoanStatus::values(...LoanStatus::disbursed()))
            ->groupBy('branch_id')
            ->selectRaw('branch_id, COUNT(*) as loans')
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as defaulted', LoanStatus::values(LoanStatus::Default, LoanStatus::WrittenOff))
            ->get()->keyBy('branch_id');
        $byBranch = $running->groupBy('branch_id');

        $row = function (string $label, Collection $loans, float $expected, float $actual, int $disbursedLoans, int $defaulted, ?int $branchId): array {
            $portfolio = round((float) $loans->sum('out_principal'), 2);

            return [
                'branch_id' => $branchId,
                'label' => $label,
                'active_loans' => $loans->count(),
                'portfolio' => $portfolio,
                'expected' => round($expected, 2),
                'collected' => round($actual, 2),
                'collection_percent' => $this->percent($actual, $expected),
                'arrears_percent' => $this->percent((float) $loans->where('dpd', '>=', 1)->sum('out_principal'), $portfolio),
                'default_percent' => $this->percent($defaulted, $disbursedLoans),
            ];
        };

        $rows = $branches->map(fn (Branch $branch): array => $row(
            $branch->name,
            $byBranch->get($branch->id, collect()),
            (float) ($expected[$branch->id] ?? 0),
            (float) ($actual[$branch->id] ?? 0),
            (int) ($disbursed[$branch->id]->loans ?? 0),
            (int) ($disbursed[$branch->id]->defaulted ?? 0),
            (int) $branch->id,
        ))->sortByDesc('portfolio')->values()->all();

        return [
            'rows' => $rows,
            'total' => $row('TOTAL', $running->whereIn('branch_id', $ids), (float) $expected->sum(), (float) $actual->sum(), (int) $disbursed->sum('loans'), (int) $disbursed->sum('defaulted'), null),
        ];
    }

    /**
     * Loan repayments ('deposit') or disbursements ('withdrawal'), reversals excluded.
     *
     * @param  list<int>|null  $branchIds
     */
    private function transactions(Company $company, ?array $branchIds, string $type): Builder
    {
        return DB::table('loan_transactions')
            ->where('loan_transactions.company_id', $company->id)
            ->when($branchIds !== null, fn ($query) => $query->whereIn('loan_transactions.branch_id', $branchIds))
            ->where('loan_transactions.type', $type)
            ->whereNull('loan_transactions.reversed_at');
    }

    private function percent(float $part, float $whole): float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : 0.0;
    }
}
