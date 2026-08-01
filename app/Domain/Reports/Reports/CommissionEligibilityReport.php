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
 * `GET /reports/commission-eligibility` — §6C, and §2 of the gap list.
 *
 *   > Branches eligible · Branches blocked (due to loss)
 *   > Branch with profit (eligible) · Branch with loss (blocked) · Reason for
 *   > blocking
 *
 * §16.2–16.3 are the rules this report exists to make visible: *"Commission
 * haitoki bila profit"* and *"Loss lazima ifidiwe kabla ya commission"*.
 *
 * The **reason** column is the whole point. "Blocked" without a reason invites
 * the branch manager to ask, and the answer is always one of two things — the
 * branch made a loss, or an earlier loss is still being paid off — which are
 * different situations with different remedies.
 */
final class CommissionEligibilityReport implements Report
{
    public function slug(): string
    {
        return 'commission-eligibility';
    }

    public function title(): string
    {
        return 'Commission Eligibility';
    }

    public function description(): string
    {
        return 'Which branches earned commission, which were blocked, and why.';
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
            ->with(['branch', 'distributions'])
            ->when($filters->branchId !== null, fn ($q) => $q->where('branch_id', $filters->branchId))
            ->when($filters->period !== null, fn ($q) => $q->where('period', $filters->period))
            ->orderByDesc('period')
            ->orderBy('branch_id')
            ->get();

        $rows = $pools->map(function (CommissionPool $pool): array {
            $profit = Money::of($pool->branch_profit);
            $loss = Money::of($pool->loss_carry_forward);
            $eligible = $pool->distributableProfit()->isPositive();

            return [
                'period' => $pool->period,
                'branch' => Cell::text($pool->branch?->name),
                'status' => $eligible ? 'Eligible' : 'Blocked',
                'branchProfit' => $pool->branch_profit,
                'lossCarried' => $pool->loss_carry_forward,
                'distributable' => $pool->distributable_profit,
                'pool' => $pool->pool_amount,
                'staffPaid' => (string) $pool->distributions->count(),
                'reason' => $this->reason($eligible, $profit, $loss),
            ];
        })->all();

        $eligible = $pools->filter(fn (CommissionPool $p): bool => $p->distributableProfit()->isPositive());
        $blocked = $pools->reject(fn (CommissionPool $p): bool => $p->distributableProfit()->isPositive());

        return new ReportResult(
            columns: [
                ReportColumn::text('period', 'Period'),
                ReportColumn::text('branch', 'Branch'),
                ReportColumn::text('status', 'Status'),
                ReportColumn::money('branchProfit', 'Branch Profit'),
                ReportColumn::money('lossCarried', 'Loss Carried'),
                ReportColumn::money('distributable', 'Distributable'),
                ReportColumn::money('pool', 'Pool'),
                ReportColumn::number('staffPaid', 'Staff Paid'),
                ReportColumn::text('reason', 'Reason'),
            ],
            rows: $rows,
            totals: [
                'period' => sprintf('%d pools', $pools->count()),
                'pool' => Money::sum(
                    $pools->map(static fn (CommissionPool $p): Money => Money::of($p->pool_amount)),
                )->toDecimalString(),
            ],
            summary: [
                ['label' => 'Eligible', 'value' => (string) $eligible->count()],
                ['label' => 'Blocked', 'value' => (string) $blocked->count()],
                [
                    'label' => 'Commission Paid',
                    'value' => Money::sum(
                        $eligible->map(static fn (CommissionPool $p): Money => Money::of($p->pool_amount)),
                    )->toDecimalString(),
                ],
            ],
            emptyMessage: 'No commission pools have been generated for these filters.',
            reconciliation: 'Eligibility is `distributable_profit > 0`, the same test CommissionPool::isEligible() applies — this report re-derives nothing. §16.2 "commission haitoki bila profit" and §16.3 "loss lazima ifidiwe kabla ya commission" are the two blocking reasons, and they are distinguished here because they call for different action.',
        );
    }

    private function reason(bool $eligible, Money $profit, Money $loss): string
    {
        if ($eligible) {
            return 'Profit after the HQ hold and any carried loss';
        }

        if (! $profit->isPositive()) {
            // §16.2 — no profit at all.
            return 'Branch made no profit this period';
        }

        if ($loss->isPositive()) {
            // §16.3 — a profit, but an earlier loss ate it.
            return sprintf('Profit absorbed by %s of carried loss', $loss->toDecimalString());
        }

        // Profit positive, no carried loss, still nothing distributable: the
        // 2% hold consumed it, which only happens on a very small profit.
        return 'Profit consumed by the head office hold';
    }
}
