<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Hr\Services\CommissionCalculator;
use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Support\Cell;
use App\Models\CommissionPool;
use App\Support\Money;

/**
 * `GET /reports/hq-allocation` — §4C, and the same report §2 of the gap list
 * calls "HQ 2% HOLD REPORT".
 *
 *   > 2% held per branch · Total HQ reserve accumulated
 *   > Profit per branch · 2% held amount · Total accumulated reserve
 *
 * Both descriptions are the same three figures, so this is one report.
 *
 * The hold is taken **before** the loss carry-forward, which is why the report
 * shows branch profit rather than distributable profit beside it: a branch in
 * loss still contributes nothing, but a branch whose profit is entirely eaten
 * by a carried loss has still paid the 2%. Reading the hold against the
 * distributable figure would make that look like an error.
 */
final class HqAllocationReport implements Report
{
    public function slug(): string
    {
        return 'hq-allocation';
    }

    public function title(): string
    {
        return 'HQ Allocation (2%)';
    }

    public function description(): string
    {
        return 'The 2% head office holds from each branch\'s profit, and the reserve it has accumulated.';
    }

    public function group(): string
    {
        return 'Financial';
    }

    public function supportedFilters(): array
    {
        return ['branchId', 'period'];
    }

    public function compute(ReportFilters $filters): ReportResult
    {
        $pools = CommissionPool::query()
            ->with('branch')
            ->when($filters->branchId !== null, fn ($q) => $q->where('branch_id', $filters->branchId))
            ->when($filters->period !== null, fn ($q) => $q->where('period', $filters->period))
            ->orderByDesc('period')
            ->orderBy('branch_id')
            ->get();

        $rows = $pools->map(fn (CommissionPool $pool): array => [
            'period' => $pool->period,
            'branch' => Cell::text($pool->branch?->name),
            'branchProfit' => $pool->branch_profit,
            'holdRate' => CommissionCalculator::HQ_HOLD_RATE,
            'held' => $pool->hq_hold_amount,

            /*
             * Shown beside the hold because the hold comes first. A branch can
             * pay the 2% and still distribute nothing — the carried loss is
             * deducted after — and without this column that reads as a bug.
             */
            'lossCarried' => $pool->loss_carry_forward,
            'distributable' => $pool->distributable_profit,
        ])->all();

        $totalHeld = Money::sum($pools->map(fn (CommissionPool $p): Money => Money::of($p->hq_hold_amount)));
        $totalProfit = Money::sum($pools->map(fn (CommissionPool $p): Money => Money::of($p->branch_profit)));

        /*
         * The reserve is everything ever held, not just what this filter shows.
         * §4C asks for "total accumulated reserve" — a figure that changed when
         * you filtered by month would not be one.
         */
        $reserve = Money::sum(
            CommissionPool::query()->pluck('hq_hold_amount')->map(static fn (string $v): Money => Money::of($v)),
        );

        return new ReportResult(
            columns: [
                ReportColumn::text('period', 'Period'),
                ReportColumn::text('branch', 'Branch'),
                ReportColumn::money('branchProfit', 'Branch Profit'),
                ReportColumn::percent('holdRate', 'Hold %'),
                ReportColumn::money('held', 'Held by HQ'),
                ReportColumn::money('lossCarried', 'Loss Carried'),
                ReportColumn::money('distributable', 'Distributable'),
            ],
            rows: $rows,
            totals: [
                'period' => sprintf('%d pools', $pools->count()),
                'branchProfit' => $totalProfit->toDecimalString(),
                'held' => $totalHeld->toDecimalString(),
            ],
            summary: [
                ['label' => 'Held (filtered)', 'value' => $totalHeld->toDecimalString()],
                ['label' => 'Accumulated Reserve', 'value' => $reserve->toDecimalString()],
                ['label' => 'Hold Rate', 'value' => CommissionCalculator::HQ_HOLD_RATE.'%'],
            ],
            emptyMessage: 'No commission pools have been generated for these filters.',
            reconciliation: 'One row per commission pool, holding the figures the engine computed when the pool was created — this report recomputes nothing. The hold is taken from branch profit BEFORE the loss carry-forward (§7 Step 1), so Held can be positive while Distributable is zero. Accumulated Reserve is every pool ever created, not only those matching the filter, because "total accumulated" is not a filtered quantity.',
        );
    }
}
