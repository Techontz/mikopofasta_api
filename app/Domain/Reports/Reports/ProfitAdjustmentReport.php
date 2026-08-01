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
 * `GET /reports/profit-adjustment` — §6B, and §5 of the gap list.
 *
 *   > Loss offset applied · Net profit after adjustment
 *   > Profit before adjustment · Loss deducted · Final profit used
 *
 * The arithmetic of §7 Step 1, laid out one column at a time:
 *
 *     Branch Profit − HQ 2% hold − Loss carry forward = Distributable
 *
 * Which is worth a report of its own because it is the step people dispute. A
 * branch manager who made a profit and received no commission wants to see
 * exactly where it went, and "distributable profit was zero" does not answer
 * that. Every column here is a figure the engine stored at the time, so the
 * answer is what actually happened rather than a re-derivation.
 */
final class ProfitAdjustmentReport implements Report
{
    public function slug(): string
    {
        return 'profit-adjustment';
    }

    public function title(): string
    {
        return 'Profit Adjustment';
    }

    public function description(): string
    {
        return 'Branch profit, the head office hold, the loss carried forward, and what was left to distribute.';
    }

    public function group(): string
    {
        return 'Branch';
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

        $rows = $pools->map(function (CommissionPool $pool): array {
            $distributable = $pool->distributableProfit();

            return [
                'period' => $pool->period,
                'branch' => Cell::text($pool->branch?->name),
                'profitBefore' => $pool->branch_profit,
                'hqHold' => $pool->hq_hold_amount,
                'lossDeducted' => $pool->loss_carry_forward,
                'profitAfter' => $pool->distributable_profit,
                'poolPaid' => $pool->pool_amount,

                /*
                 * The outcome in a word, because that is what a reader is here
                 * for. "Blocked" is §16's rule — a branch in loss pays no
                 * commission — and "Absorbed" is the case that surprises
                 * people: a real profit, entirely consumed by an earlier loss.
                 */
                'outcome' => match (true) {
                    $distributable->isPositive() => 'Distributed',
                    Money::of($pool->branch_profit)->isPositive() => 'Absorbed by loss',
                    default => 'Blocked (loss)',
                },
            ];
        })->all();

        $sum = fn (string $column): Money => Money::sum(
            $pools->map(static fn (CommissionPool $p): Money => Money::of($p->{$column})),
        );

        $absorbed = $pools->filter(
            fn (CommissionPool $p): bool => ! $p->distributableProfit()->isPositive()
                && Money::of($p->branch_profit)->isPositive(),
        );

        return new ReportResult(
            columns: [
                ReportColumn::text('period', 'Period'),
                ReportColumn::text('branch', 'Branch'),
                ReportColumn::money('profitBefore', 'Profit Before'),
                ReportColumn::money('hqHold', 'HQ Hold'),
                ReportColumn::money('lossDeducted', 'Loss Deducted'),
                ReportColumn::money('profitAfter', 'Profit After'),
                ReportColumn::money('poolPaid', 'Commission Pool'),
                ReportColumn::text('outcome', 'Outcome'),
            ],
            rows: $rows,
            totals: [
                'period' => sprintf('%d pools', $pools->count()),
                'profitBefore' => $sum('branch_profit')->toDecimalString(),
                'hqHold' => $sum('hq_hold_amount')->toDecimalString(),
                'lossDeducted' => $sum('loss_carry_forward')->toDecimalString(),
                'profitAfter' => $sum('distributable_profit')->toDecimalString(),
                'poolPaid' => $sum('pool_amount')->toDecimalString(),
            ],
            summary: [
                ['label' => 'Loss Deducted', 'value' => $sum('loss_carry_forward')->toDecimalString()],
                ['label' => 'Profit Absorbed', 'value' => (string) $absorbed->count()],
                ['label' => 'Commission Paid', 'value' => $sum('pool_amount')->toDecimalString()],
            ],
            emptyMessage: 'No commission pools have been generated for these filters.',
            reconciliation: 'Profit Before − HQ Hold − Loss Deducted = Profit After, on every row, using the figures the commission engine stored when the pool was created. "Absorbed by loss" is a branch that genuinely made a profit and still distributed nothing, which is §16.3 working rather than failing.',
        );
    }
}
