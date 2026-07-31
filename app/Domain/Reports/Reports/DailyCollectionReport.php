<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Services\ReportSources;
use App\Support\Money;

/**
 * `GET /reports/daily-collection` — collections grouped by day.
 *
 * The same payments the Repayments report lists, aggregated. It reads through
 * the same accessor for exactly that reason: a daily total that disagreed with
 * the detail behind it would send someone hunting for a payment that was never
 * missing.
 */
final class DailyCollectionReport implements Report
{
    public function __construct(private readonly ReportSources $sources) {}

    public function slug(): string
    {
        return 'daily-collection';
    }

    public function title(): string
    {
        return 'Daily Collection';
    }

    public function description(): string
    {
        return 'Collections grouped by day.';
    }

    public function group(): string
    {
        return 'Collections';
    }

    public function supportedFilters(): array
    {
        return ['branchId', 'from', 'to', 'period'];
    }

    public function compute(ReportFilters $filters): ReportResult
    {
        /** @var array<string, array{payments: int, amount: Money}> $byDay */
        $byDay = [];

        foreach ($this->sources->collectedPayments($filters) as $payment) {
            $day = $payment->received_at->toDateString();
            $byDay[$day] ??= ['payments' => 0, 'amount' => Money::zero()];
            $byDay[$day]['payments']++;
            $byDay[$day]['amount'] = $byDay[$day]['amount']->add($payment->amountMoney());
        }

        krsort($byDay);

        $rows = [];
        $total = Money::zero();
        $count = 0;

        foreach ($byDay as $day => $figures) {
            $rows[] = [
                'day' => $day,
                'payments' => $figures['payments'],
                'amount' => $figures['amount']->toDecimalString(),
            ];

            $total = $total->add($figures['amount']);
            $count += $figures['payments'];
        }

        return new ReportResult(
            columns: [
                ReportColumn::text('day', 'Date'),
                ReportColumn::number('payments', 'Payments'),
                ReportColumn::money('amount', 'Collected'),
            ],
            rows: $rows,
            totals: ['day' => 'Total', 'payments' => $count, 'amount' => $total->toDecimalString()],
            summary: [
                ['label' => 'Days with Collections', 'value' => (string) count($rows)],
                ['label' => 'Total Collected', 'value' => $total->toDecimalString()],
            ],
            emptyMessage: 'No collections in this window.',
            reconciliation: 'Sums to exactly the same figure as the Repayments report over the same filters — both read the one payments accessor.',
        );
    }
}
