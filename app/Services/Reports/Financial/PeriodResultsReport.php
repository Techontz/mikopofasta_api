<?php

namespace App\Services\Reports\Financial;

use App\Models\BranchPeriodResult;
use Illuminate\Support\Collection;

/**
 * Reports read from the month-end results (branch_period_results):
 *
 * - HQ 2% HOLD REPORT: profit per branch, 2 % held amount, total accumulated reserve;
 * - LOSS CARRY FORWARD REPORT / Loss Tracking: previous loss, current adjustments, remaining loss per
 *   branch, months in loss streak, and the commission eligibility that follows (blocked while in loss).
 *
 * Inferred: months that have been calculated but not yet closed are shown as PROVISIONAL and are not
 * counted in the accumulated (posted) HQ reserve.
 */
class PeriodResultsReport
{
    /**
     * @return array<string, mixed>
     */
    public function hqHold(FinancialScope $scope): array
    {
        $results = $this->results($scope, $scope->to->format('Y-m-01'));
        $cumulative = [];
        $rows = [];

        foreach ($results as $result) {
            $closed = $result->period->isClosed();
            $cumulative[$result->branch_id] = round(($cumulative[$result->branch_id] ?? 0) + ($closed ? (float) $result->hq_hold_amount : 0), 2);
            if ($result->period->period_start->lt($scope->from->startOfMonth())) {
                continue;
            }
            $rows[] = [
                'id' => $result->id,
                'month' => $result->period->period_start->format('Y-m'),
                'branch_id' => (string) $result->branch_id,
                'branch' => $result->branch?->name,
                'gross_profit' => (float) $result->gross_profit,
                'loss_brought_forward' => (float) $result->loss_brought_forward,
                'net_profit' => (float) $result->net_profit,
                'hq_hold_percent' => (float) $result->hq_hold_percent,
                'hq_hold_amount' => (float) $result->hq_hold_amount,
                'distributable_profit' => (float) $result->distributable_profit,
                'status' => $closed ? 'CLOSED' : 'PROVISIONAL',
                'accumulated' => $cumulative[$result->branch_id],
            ];
        }

        $inRange = collect($rows);
        $branches = $inRange->groupBy('branch_id')->map(fn (Collection $branchRows, string $branchId): array => [
            'branch_id' => $branchId,
            'branch' => $branchRows->first()['branch'],
            'net_profit' => round($branchRows->sum('net_profit'), 2),
            'held' => round($branchRows->where('status', 'CLOSED')->sum('hq_hold_amount'), 2),
            'provisional' => round($branchRows->where('status', 'PROVISIONAL')->sum('hq_hold_amount'), 2),
            'accumulated' => $cumulative[(int) $branchId] ?? 0.0,
        ])->values()->all();

        $months = $inRange->groupBy('month')->map(fn (Collection $monthRows, string $month): array => [
            'month' => $month,
            'held' => round($monthRows->sum('hq_hold_amount'), 2),
        ])->sortKeys()->values()->all();

        return [
            'rows' => $rows,
            'branches' => $branches,
            'months' => $months,
            'total_held' => round($inRange->where('status', 'CLOSED')->sum('hq_hold_amount'), 2),
            'total_provisional' => round($inRange->where('status', 'PROVISIONAL')->sum('hq_hold_amount'), 2),
            'total_accumulated' => round(array_sum($cumulative), 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function lossCarryForward(FinancialScope $scope): array
    {
        $results = $this->results($scope, $scope->to->format('Y-m-01'));
        $streaks = [];
        $rows = [];

        foreach ($results as $result) {
            $inLoss = (float) $result->loss_carried_forward > 0;
            $streaks[$result->branch_id] = $inLoss ? ($streaks[$result->branch_id] ?? 0) + 1 : 0;
            if ($result->period->period_start->lt($scope->from->startOfMonth())) {
                continue;
            }

            $previous = (float) $result->loss_brought_forward;
            $gross = (float) $result->gross_profit;
            $offset = $previous > 0 && $gross > 0 ? min($previous, $gross) : 0.0;
            $added = $gross < 0 ? -$gross : 0.0;

            $rows[] = [
                'id' => $result->id,
                'month' => $result->period->period_start->format('Y-m'),
                'branch_id' => (string) $result->branch_id,
                'branch' => $result->branch?->name,
                'gross_profit' => $gross,
                'previous_loss' => $previous,
                'loss_offset' => round($offset, 2),
                'loss_added' => round($added, 2),
                'current_adjustment' => round($added - $offset, 2),
                'remaining_loss' => (float) $result->loss_carried_forward,
                'net_profit' => (float) $result->net_profit,
                'loss_streak' => $streaks[$result->branch_id],
                'commission_eligible' => (bool) $result->commission_eligible,
                'blocked_reason' => $result->commission_eligible ? null : ($inLoss ? 'Loss carried forward' : 'No distributable profit'),
                'status' => $result->period->isClosed() ? 'CLOSED' : 'PROVISIONAL',
            ];
        }

        $branches = collect($rows)->groupBy('branch_id')->map(function (Collection $branchRows): array {
            $latest = $branchRows->last();

            return [
                'branch_id' => $latest['branch_id'],
                'branch' => $latest['branch'],
                'latest_month' => $latest['month'],
                'loss_offset' => round($branchRows->sum('loss_offset'), 2),
                'loss_added' => round($branchRows->sum('loss_added'), 2),
                'remaining_loss' => $latest['remaining_loss'],
                'loss_streak' => $latest['loss_streak'],
                'commission_eligible' => $latest['commission_eligible'],
            ];
        })->values();

        return [
            'rows' => $rows,
            'branches' => $branches->all(),
            'branches_in_loss' => $branches->where('remaining_loss', '>', 0)->count(),
            'total_remaining_loss' => round($branches->sum('remaining_loss'), 2),
        ];
    }

    /**
     * All results up to the "to" month (earlier months are needed for streaks and accumulation), oldest first.
     *
     * @return Collection<int, BranchPeriodResult>
     */
    private function results(FinancialScope $scope, string $untilMonth): Collection
    {
        return BranchPeriodResult::query()
            ->join('accounting_periods', 'accounting_periods.id', '=', 'branch_period_results.accounting_period_id')
            ->where('accounting_periods.company_id', $scope->companyId)
            ->whereDate('accounting_periods.period_start', '<=', $untilMonth)
            ->when($scope->branchIds !== null, fn ($query) => $query->whereIn('branch_period_results.branch_id', $scope->branchIds === [] ? [0] : $scope->branchIds))
            ->orderBy('accounting_periods.period_start')
            ->orderBy('branch_period_results.branch_id')
            ->select('branch_period_results.*')
            ->with(['period', 'branch:id,name'])
            ->get();
    }
}
