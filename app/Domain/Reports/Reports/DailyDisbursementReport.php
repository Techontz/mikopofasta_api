<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Services\ReportSources;
use App\Models\Loan;
use App\Support\Money;

/**
 * `GET /reports/daily-disbursement` — loans disbursed, grouped by day.
 *
 * Grouped on `disbursement_date`, which §6 stamps at the provider callback —
 * the same moment the Loan Receivable debit is posted. That is why the
 * principal here equals the debits to Loan Receivable over the same window.
 */
final class DailyDisbursementReport implements Report
{
    public function __construct(private readonly ReportSources $sources) {}

    public function slug(): string
    {
        return 'daily-disbursement';
    }

    public function title(): string
    {
        return 'Daily Disbursement';
    }

    public function description(): string
    {
        return 'Loans disbursed, grouped by day.';
    }

    public function group(): string
    {
        return 'Portfolio';
    }

    public function supportedFilters(): array
    {
        return ['branchId', 'from', 'to', 'period'];
    }

    public function compute(ReportFilters $filters): ReportResult
    {
        /** @var array<string, array{loans: int, amount: Money}> $byDay */
        $byDay = [];

        foreach ($this->sources->liveLoans($filters) as $loan) {
            if ($loan->disbursement_date === null || ! $filters->covers($loan->disbursement_date)) {
                continue;
            }

            $day = $loan->disbursement_date->toDateString();
            $byDay[$day] ??= ['loans' => 0, 'amount' => Money::zero()];
            $byDay[$day]['loans']++;
            $byDay[$day]['amount'] = $byDay[$day]['amount']->add($loan->principal());
        }

        // Newest first — a collections officer reads today's figure first.
        krsort($byDay);

        $rows = [];
        $total = Money::zero();
        $loanCount = 0;

        foreach ($byDay as $day => $figures) {
            $rows[] = [
                'day' => $day,
                'loans' => $figures['loans'],
                'amount' => $figures['amount']->toDecimalString(),
            ];

            $total = $total->add($figures['amount']);
            $loanCount += $figures['loans'];
        }

        return new ReportResult(
            columns: [
                ReportColumn::text('day', 'Date'),
                ReportColumn::number('loans', 'Loans'),
                ReportColumn::money('amount', 'Disbursed'),
            ],
            rows: $rows,
            totals: ['day' => 'Total', 'loans' => $loanCount, 'amount' => $total->toDecimalString()],
            summary: [
                ['label' => 'Days with Disbursements', 'value' => (string) count($rows)],
                ['label' => 'Total Disbursed', 'value' => $total->toDecimalString()],
            ],
            emptyMessage: 'No disbursements in this window.',
            reconciliation: 'Principal disbursed equals the debits posted to Loan Receivable at disbursement (§5).',
        );
    }
}
