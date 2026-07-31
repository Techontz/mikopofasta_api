<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Support\Cell;
use App\Models\CommissionPool;
use App\Support\Money;

/**
 * `GET /reports/commission` — branch pools and the staff shares drawn from
 * them.
 *
 * Every column is a stored figure from `commission_pools`, not a recomputation:
 * the pool that was actually awarded is what a manager is owed an explanation
 * for, and recomputing it here would answer a different question if branch
 * profit has moved since.
 */
final class CommissionReport implements Report
{
    public function slug(): string
    {
        return 'commission';
    }

    public function title(): string
    {
        return 'Commission';
    }

    public function description(): string
    {
        return 'Branch pools and the staff shares drawn from them.';
    }

    public function group(): string
    {
        return 'HR';
    }

    public function supportedFilters(): array
    {
        return ['branchId', 'period'];
    }

    public function compute(ReportFilters $filters): ReportResult
    {
        $pools = CommissionPool::query()
            ->with(['branch', 'distributions'])
            ->when($filters->branchId !== null, fn ($q) => $q->where('branch_id', $filters->branchId))
            ->when($filters->period !== null, fn ($q) => $q->where('period', $filters->period))
            ->orderByDesc('period')
            ->orderBy('branch_id')
            ->get();

        $rows = $pools->map(fn (CommissionPool $pool): array => [
            'branch' => Cell::text($pool->branch?->name),
            'period' => $pool->period,
            'branchProfit' => $pool->branch_profit,
            'lossCarryForward' => $pool->loss_carry_forward,
            'hqHold' => $pool->hq_hold_amount,
            'distributable' => $pool->distributable_profit,
            'pool' => $pool->pool_amount,
            'recipients' => $pool->distributions->count(),
            'status' => $pool->isDistributable() ? 'Distributable' : 'Blocked — loss not offset',
        ])->all();

        $distributed = Money::sum(
            $pools->flatMap->distributions->map(fn ($d): Money => $d->shareAmount()),
        );

        return new ReportResult(
            columns: [
                ReportColumn::text('branch', 'Branch'),
                ReportColumn::text('period', 'Period'),
                ReportColumn::money('branchProfit', 'Branch Profit'),
                ReportColumn::money('lossCarryForward', 'Loss c/f'),
                ReportColumn::money('hqHold', 'HQ Hold'),
                ReportColumn::money('distributable', 'Distributable'),
                ReportColumn::money('pool', 'Pool'),
                ReportColumn::number('recipients', 'Recipients'),
                ReportColumn::text('status', 'Status'),
            ],
            rows: $rows,
            totals: [
                'branch' => 'Total',
                'branchProfit' => Money::sum($pools->map(fn (CommissionPool $p): Money => $p->branchProfit()))->toDecimalString(),
                'pool' => Money::sum($pools->map(fn (CommissionPool $p): Money => $p->poolAmount()))->toDecimalString(),
                'recipients' => $pools->sum(fn (CommissionPool $p): int => $p->distributions->count()),
            ],
            summary: [
                ['label' => 'Pools', 'value' => (string) $pools->count()],
                ['label' => 'Blocked by Loss', 'value' => (string) $pools->reject(fn (CommissionPool $p): bool => $p->isDistributable())->count()],
                ['label' => 'Distributed', 'value' => $distributed->toDecimalString()],
            ],
            emptyMessage: 'No commission pools for these filters.',
            reconciliation: 'A pool with non-positive distributable profit pays nothing and has no recipients — §11\'s rule that a branch loss must be offset first. Distributed shares are expensed to Commission Expense on each recipient\'s payroll recognition entry, never as a pool-level posting, so the total distributed for a finalized period equals the Commission Expense balance. Note that Branch Profit here is the figure AS AT POOL GENERATION, not a live one: §11 sequences month-end close → commission → payroll, so the period\'s salary expense is posted after the pool is struck and the Branch P&L report will legitimately show a smaller — often negative — profit for the same period.',
        );
    }
}
