<?php

namespace App\Services\Hrm;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Goal;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Models\LoanTransaction;
use App\Models\StaffPerformanceReview;
use App\Services\LoanService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Per-officer KPIs (STAFF COMMISSION §15 "Performance Management", OVERVIEW ALL REPORT "Staff
 * Performance Report: targets vs achievement, productivity metrics").
 *
 * Metric keys match the Goals module (new_customers, loans_count, disbursement_amount,
 * collections_amount) so an officer's goals can be compared with actual figures.
 */
class PerformanceMetrics
{
    /** Loan statuses that carry an outstanding balance. */
    private const OPEN_LOAN_STATUSES = ['disbursed', 'active', 'default'];

    public function __construct(private readonly LoanService $loans) {}

    /**
     * @param  Collection<int, Employee>  $employees
     * @return list<array<string, mixed>>
     */
    public function forEmployees(Collection $employees, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = $employees->pluck('id');
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $customers = Customer::whereIn('employee_id', $ids)->whereDate('created_at', '>=', $fromDate)->whereDate('created_at', '<=', $toDate)
            ->selectRaw('employee_id, COUNT(*) AS total')->groupBy('employee_id')->pluck('total', 'employee_id');

        $disbursed = Loan::whereIn('employee_id', $ids)->whereNotNull('withdrawn_at')->whereDate('withdrawn_at', '>=', $fromDate)->whereDate('withdrawn_at', '<=', $toDate)
            ->selectRaw('employee_id, COUNT(*) AS loans, COALESCE(SUM(amount_approved), 0) AS amount')->groupBy('employee_id')->get()->keyBy('employee_id');

        $collections = LoanTransaction::query()
            ->join('loans', 'loans.id', '=', 'loan_transactions.loan_id')
            ->whereIn('loans.employee_id', $ids)
            ->where('loan_transactions.type', 'deposit')
            ->whereNull('loan_transactions.reversed_at')
            ->whereDate('loan_transactions.transaction_date', '>=', $fromDate)
            ->whereDate('loan_transactions.transaction_date', '<=', $toDate)
            ->selectRaw('loans.employee_id, COALESCE(SUM(loan_transactions.amount), 0) AS amount')
            ->groupBy('loans.employee_id')
            ->pluck('amount', 'employee_id');

        $schedules = LoanSchedule::query()
            ->join('loans', 'loans.id', '=', 'loan_schedules.loan_id')
            ->whereIn('loans.employee_id', $ids)
            ->whereDate('loan_schedules.due_date', '>=', $fromDate)
            ->whereDate('loan_schedules.due_date', '<=', $toDate)
            ->selectRaw('loans.employee_id, COALESCE(SUM(loan_schedules.amount), 0) AS expected, COALESCE(SUM(loan_schedules.paid_amount), 0) AS paid')
            ->groupBy('loans.employee_id')
            ->get()
            ->keyBy('employee_id');

        $goals = $this->goals($ids->all(), $from, $to);
        $reviews = StaffPerformanceReview::whereIn('employee_id', $ids)->latest('period')->latest('id')->get()->unique('employee_id')->keyBy('employee_id');

        return $employees->map(function (Employee $employee) use ($customers, $disbursed, $collections, $schedules, $goals, $reviews, $to): array {
            $expected = (float) ($schedules->get($employee->id)?->expected ?? 0);
            $paid = (float) ($schedules->get($employee->id)?->paid ?? 0);
            [$portfolio, $atRisk] = $this->portfolioAtRisk($employee->id, $to);

            $actual = [
                'new_customers' => (int) ($customers[$employee->id] ?? 0),
                'loans_count' => (int) ($disbursed->get($employee->id)?->loans ?? 0),
                'disbursement_amount' => round((float) ($disbursed->get($employee->id)?->amount ?? 0), 2),
                'collections_amount' => round((float) ($collections[$employee->id] ?? 0), 2),
            ];

            $review = $reviews->get($employee->id);

            return [
                'employee_id' => $employee->id,
                'employee' => $employee->full_name,
                'branch' => $employee->branch?->name,
                'role' => $employee->role?->name,
                ...$actual,
                'expected_collections' => round($expected, 2),
                'collection_rate' => $expected > 0 ? round($paid / $expected * 100, 2) : null,
                'portfolio_outstanding' => $portfolio,
                'par30_amount' => $atRisk,
                'par30' => $portfolio > 0 ? round($atRisk / $portfolio * 100, 2) : 0.0,
                'goals' => collect($goals[$employee->id] ?? [])->map(fn (Goal $goal): array => [
                    'id' => $goal->id,
                    'title' => $goal->title,
                    'metric' => $goal->metric,
                    'target' => (float) $goal->target,
                    'actual' => $actual[$goal->metric] ?? null,
                    'progress' => isset($actual[$goal->metric]) && (float) $goal->target > 0 ? round($actual[$goal->metric] / (float) $goal->target * 100, 1) : null,
                ])->values()->all(),
                'latest_review' => $review ? ['period' => $review->period->format('Y-m'), 'rating' => $review->rating, 'discipline' => $review->discipline] : null,
            ];
        })->values()->all();
    }

    /**
     * Outstanding balance of the officer's open loans and the part with an instalment more than 30 days overdue.
     *
     * @return array{0: float, 1: float}
     */
    private function portfolioAtRisk(int $employeeId, CarbonImmutable $asOf): array
    {
        $loans = Loan::where('employee_id', $employeeId)->whereIn('status', self::OPEN_LOAN_STATUSES)->get();
        $portfolio = 0.0;
        $atRisk = 0.0;

        foreach ($loans as $loan) {
            $outstanding = $this->loans->outstanding($loan)['total'];
            $portfolio += $outstanding;

            $overdue = LoanSchedule::where('loan_id', $loan->id)
                ->whereDate('due_date', '<', $asOf->subDays(30)->toDateString())
                ->whereColumn('paid_amount', '<', 'amount')
                ->exists();

            if ($overdue) {
                $atRisk += $outstanding;
            }
        }

        return [round($portfolio, 2), round($atRisk, 2)];
    }

    /**
     * Goals set for the officers overlapping the period (optional: the Goals module may be absent).
     *
     * @param  list<int>  $employeeIds
     * @return array<int, Collection<int, Goal>>
     */
    private function goals(array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! class_exists(Goal::class) || ! Schema::hasTable('goals')) {
            return [];
        }

        return Goal::whereIn('employee_id', $employeeIds)
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->orderBy('id')
            ->get()
            ->groupBy('employee_id')
            ->all();
    }
}
