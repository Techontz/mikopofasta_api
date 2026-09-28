<?php

namespace App\Services\Goals;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Goal;
use App\Models\Loan;
use App\Models\LoanTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Measures goal achievement from existing operational data.
 *
 * - new_customers: customers registered (created_at) in the period;
 * - loans_count / disbursement_amount: loans cashed out to customers (loan_transactions type "withdrawal");
 * - collections_amount: repayments received (loan_transactions type "deposit").
 *
 * Inferred: officer goals are attributed to the loan officer on the loan / customer (employee_id),
 * zone goals to all branches of the zone, company goals to every branch.
 */
class GoalProgress
{
    /**
     * @return array{achieved: float, target: float, percent: float, remaining: float, expected_percent: float, status: string}
     */
    public function summary(Goal $goal, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $achieved = $this->achieved($goal);
        $target = (float) $goal->target;
        $percent = $target > 0 ? round($achieved / $target * 100, 1) : 0.0;

        $start = CarbonImmutable::parse($goal->start_date);
        $end = CarbonImmutable::parse($goal->end_date);
        $totalDays = max(1, (int) $start->diffInDays($end) + 1);
        $elapsedDays = $today->lt($start) ? 0 : min($totalDays, (int) $start->diffInDays($today) + 1);
        $expected = round($elapsedDays / $totalDays * 100, 1);

        $status = match (true) {
            $achieved >= $target && $target > 0 => 'achieved',
            $today->lt($start) => 'upcoming',
            $today->gt($end) => 'missed',
            $percent >= $expected => 'on_track',
            default => 'behind',
        };

        return [
            'achieved' => round($achieved, 2),
            'target' => $target,
            'percent' => $percent,
            'remaining' => round(max(0, $target - $achieved), 2),
            'expected_percent' => $expected,
            'status' => $status,
        ];
    }

    public function achieved(Goal $goal): float
    {
        $start = CarbonImmutable::parse($goal->start_date)->toDateString();
        $end = CarbonImmutable::parse($goal->end_date)->toDateString();

        return match ($goal->metric) {
            'new_customers' => (float) $this->customers($goal)->whereDate('created_at', '>=', $start)->whereDate('created_at', '<=', $end)->count(),
            'loans_count' => (float) $this->transactions($goal, 'withdrawal')->whereBetween('transaction_date', [$start, $end])->count(),
            'disbursement_amount' => (float) $this->transactions($goal, 'withdrawal')->whereBetween('transaction_date', [$start, $end])->sum('amount'),
            'collections_amount' => (float) $this->transactions($goal, 'deposit')->whereBetween('transaction_date', [$start, $end])->sum('amount'),
            default => 0.0,
        };
    }

    /**
     * Cumulative achievement vs. a straight target line across the goal period, bucketed by
     * day (≤ 62 days), week (≤ 26 weeks) or month.
     *
     * @return list<array{label: string, value: float, cumulative: float, target_line: float}>
     */
    public function series(Goal $goal): array
    {
        $start = CarbonImmutable::parse($goal->start_date);
        $end = CarbonImmutable::parse($goal->end_date);
        $daily = $this->dailyValues($goal, $start, $end);

        $days = (int) $start->diffInDays($end) + 1;
        $bucket = $days <= 62 ? 'day' : ($days <= 182 ? 'week' : 'month');

        $buckets = [];
        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $key = match ($bucket) {
                'day' => $day->toDateString(),
                'week' => $day->startOfWeek()->toDateString(),
                default => $day->format('Y-m'),
            };
            $buckets[$key]['value'] = ($buckets[$key]['value'] ?? 0) + ($daily[$day->toDateString()] ?? 0);
            $buckets[$key]['last_day'] = $day;
        }

        $target = (float) $goal->target;
        $cumulative = 0.0;
        $series = [];
        foreach ($buckets as $label => $row) {
            $cumulative += $row['value'];
            $elapsed = (int) $start->diffInDays($row['last_day']) + 1;
            $series[] = [
                'label' => (string) $label,
                'value' => round($row['value'], 2),
                'cumulative' => round($cumulative, 2),
                'target_line' => round($target * $elapsed / $days, 2),
            ];
        }

        return $series;
    }

    /**
     * @return array<string, float>
     */
    private function dailyValues(Goal $goal, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $from = $start->toDateString();
        $to = $end->toDateString();

        $query = match ($goal->metric) {
            'new_customers' => $this->customers($goal)->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)
                ->selectRaw('DATE(created_at) as day, COUNT(*) as total'),
            'loans_count' => $this->transactions($goal, 'withdrawal')->whereBetween('transaction_date', [$from, $to])
                ->selectRaw('transaction_date as day, COUNT(*) as total'),
            'disbursement_amount' => $this->transactions($goal, 'withdrawal')->whereBetween('transaction_date', [$from, $to])
                ->selectRaw('transaction_date as day, SUM(amount) as total'),
            default => $this->transactions($goal, 'deposit')->whereBetween('transaction_date', [$from, $to])
                ->selectRaw('transaction_date as day, SUM(amount) as total'),
        };

        return $query->groupBy('day')->toBase()->get()
            ->mapWithKeys(fn (object $row): array => [substr((string) $row->day, 0, 10) => (float) $row->total])
            ->all();
    }

    /**
     * @return Builder<Customer>
     */
    private function customers(Goal $goal): Builder
    {
        $query = Customer::query()->where('company_id', $goal->company_id);

        return match ($goal->scope_type) {
            'branch' => $query->where('branch_id', $goal->branch_id),
            'zone' => $query->whereIn('branch_id', Branch::where('zone_id', $goal->zone_id)->select('id')),
            'employee' => $query->where('employee_id', $goal->employee_id),
            default => $query,
        };
    }

    /**
     * @return Builder<LoanTransaction>
     */
    private function transactions(Goal $goal, string $type): Builder
    {
        $query = LoanTransaction::query()->where('company_id', $goal->company_id)->where('type', $type)->whereNull('reversed_at');

        return match ($goal->scope_type) {
            'branch' => $query->where('branch_id', $goal->branch_id),
            'zone' => $query->whereIn('branch_id', Branch::where('zone_id', $goal->zone_id)->select('id')),
            'employee' => $query->whereIn('loan_id', Loan::where('employee_id', $goal->employee_id)->select('id')),
            default => $query,
        };
    }
}
