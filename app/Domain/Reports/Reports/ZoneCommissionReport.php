<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Support\Cell;
use App\Models\ZoneCommissionDistribution;
use App\Support\Money;

/**
 * `GET /reports/zone-commission` — zone manager overrides on the pools they
 * oversee.
 *
 * `postedIn` is the point of the report: it shows the payroll entry the
 * override was expensed on, which is how a reader can see that it was
 * recognised once rather than twice.
 */
final class ZoneCommissionReport implements Report
{
    public function slug(): string
    {
        return 'zone-commission';
    }

    public function title(): string
    {
        return 'Zone Commission';
    }

    public function description(): string
    {
        return 'Zone manager overrides on the pools they oversee.';
    }

    public function group(): string
    {
        return 'HR';
    }

    public function supportedFilters(): array
    {
        return ['period'];
    }

    public function compute(ReportFilters $filters): ReportResult
    {
        $overrides = ZoneCommissionDistribution::query()
            ->with(['zone', 'journalEntry'])
            ->when($filters->period !== null, fn ($q) => $q->where('period', $filters->period))
            ->orderByDesc('period')
            ->get();

        $rows = $overrides->map(fn (ZoneCommissionDistribution $z): array => [
            'zone' => Cell::text($z->zone?->name),
            'period' => $z->period,
            'poolBase' => $z->total_pool_base,
            'overridePct' => $z->override_percentage,
            'override' => $z->override_amount,

            // Null while the period's payroll is still a draft — the override
            // is expensed at finalization, not before.
            'postedIn' => Cell::pending($z->journalEntry?->entry_number, 'Not yet posted'),
        ])->all();

        $total = Money::sum($overrides->map(fn (ZoneCommissionDistribution $z): Money => $z->overrideAmount()));

        return new ReportResult(
            columns: [
                ReportColumn::text('zone', 'Zone'),
                ReportColumn::text('period', 'Period'),
                ReportColumn::money('poolBase', 'Pool Base'),
                ReportColumn::percent('overridePct', 'Override'),
                ReportColumn::money('override', 'Amount'),
                ReportColumn::text('postedIn', 'Posted In'),
            ],
            rows: $rows,
            totals: ['zone' => 'Total', 'override' => $total->toDecimalString()],
            summary: [
                ['label' => 'Overrides', 'value' => (string) count($rows)],
                ['label' => 'Total Override', 'value' => $total->toDecimalString()],
            ],
            emptyMessage: 'No zone overrides for this period.',
            reconciliation: 'The override is folded into the zone manager\'s payroll line rather than posted as a separate pool-level entry, so it is expensed exactly once — the Posted In column names that entry.',
        );
    }
}
