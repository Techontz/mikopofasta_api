<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Services\ReportSources;
use App\Models\Customer;
use App\Models\Loan;
use App\Support\Money;

/**
 * `GET /reports/growth` — §10B of the reports document.
 *
 *   > Loan growth · Revenue growth · Branch expansion performance
 *
 * One row per month, each compared with the month before it. Growth is a
 * derivative: a single month's figures are not growth at all, so the report is
 * necessarily a series and the month-on-month change is the substance.
 *
 * ## Why disbursement date rather than application date
 *
 * A loan applied for in March and disbursed in April grew the book in April —
 * that is when the money left. §6 puts the ledger entry at the disbursement
 * callback for the same reason, so this report and the portfolio agree.
 *
 * ## Revenue
 *
 * What was collected, not what was billed. An invoice nobody paid is not
 * revenue growth, and the collected figure is the one that reconciles to cash.
 */
final class GrowthReport implements Report
{
    public function __construct(private readonly ReportSources $sources) {}

    public function slug(): string
    {
        return 'growth';
    }

    public function title(): string
    {
        return 'Growth';
    }

    public function description(): string
    {
        return 'Loans, customers and collections month by month, with the change on the month before.';
    }

    public function group(): string
    {
        return 'Portfolio';
    }

    public function supportedFilters(): array
    {
        return ['branchId', 'from', 'to'];
    }

    public function compute(ReportFilters $filters): ReportResult
    {
        $months = [];

        $touch = function (string $month) use (&$months): void {
            $months[$month] ??= [
                'loans' => 0,
                'disbursed' => Money::zero(),
                'customers' => 0,
                'collected' => Money::zero(),
            ];
        };

        // Loans, by the month the money actually left.
        $loans = Loan::query()
            ->whereNotNull('disbursement_date')
            ->when($filters->branchId !== null, fn ($q) => $q->where('branch_id', $filters->branchId))
            ->when($filters->from !== null, fn ($q) => $q->whereDate('disbursement_date', '>=', $filters->from))
            ->when($filters->to !== null, fn ($q) => $q->whereDate('disbursement_date', '<=', $filters->to))
            ->get(['id', 'principal_amount', 'disbursement_date']);

        foreach ($loans as $loan) {
            $month = $loan->disbursement_date?->format('Y-m');

            if ($month === null) {
                continue;
            }

            $touch($month);
            $months[$month]['loans']++;
            $months[$month]['disbursed'] = $months[$month]['disbursed']->add(Money::of($loan->principal_amount));
        }

        // Customers, by when they were registered.
        $customers = Customer::query()
            ->when($filters->branchId !== null, fn ($q) => $q->where('branch_id', $filters->branchId))
            ->when($filters->from !== null, fn ($q) => $q->whereDate('created_at', '>=', $filters->from))
            ->when($filters->to !== null, fn ($q) => $q->whereDate('created_at', '<=', $filters->to))
            ->get(['id', 'created_at']);

        foreach ($customers as $customer) {
            $month = $customer->created_at?->format('Y-m');

            if ($month === null) {
                continue;
            }

            $touch($month);
            $months[$month]['customers']++;
        }

        foreach ($this->sources->collectedPayments($filters) as $payment) {
            $month = $payment->received_at->format('Y-m');
            $touch($month);
            $months[$month]['collected'] = $months[$month]['collected']->add($payment->amountMoney());
        }

        ksort($months);

        $rows = [];
        $previous = null;

        foreach ($months as $month => $figures) {
            $rows[] = [
                'month' => $month,
                'loans' => (string) $figures['loans'],
                'disbursed' => $figures['disbursed']->toDecimalString(),
                'disbursedGrowth' => $this->growth(
                    $previous === null ? null : $previous['disbursed'],
                    $figures['disbursed'],
                ),
                'customers' => (string) $figures['customers'],
                'collected' => $figures['collected']->toDecimalString(),
                'collectedGrowth' => $this->growth(
                    $previous === null ? null : $previous['collected'],
                    $figures['collected'],
                ),
            ];

            $previous = $figures;
        }

        $rows = array_reverse($rows);

        $totalDisbursed = Money::sum(array_map(
            static fn (array $f): Money => $f['disbursed'],
            array_values($months),
        ));
        $totalCollected = Money::sum(array_map(
            static fn (array $f): Money => $f['collected'],
            array_values($months),
        ));

        return new ReportResult(
            columns: [
                ReportColumn::text('month', 'Month'),
                ReportColumn::number('loans', 'Loans'),
                ReportColumn::money('disbursed', 'Disbursed'),
                ReportColumn::percent('disbursedGrowth', 'Disbursed Growth'),
                ReportColumn::number('customers', 'New Customers'),
                ReportColumn::money('collected', 'Collected'),
                ReportColumn::percent('collectedGrowth', 'Collection Growth'),
            ],
            rows: $rows,
            totals: [
                'month' => sprintf('%d months', count($months)),
                'loans' => (string) $loans->count(),
                'disbursed' => $totalDisbursed->toDecimalString(),
                'customers' => (string) $customers->count(),
                'collected' => $totalCollected->toDecimalString(),
            ],
            summary: [
                ['label' => 'Months', 'value' => (string) count($months)],
                ['label' => 'Disbursed', 'value' => $totalDisbursed->toDecimalString()],
                ['label' => 'Collected', 'value' => $totalCollected->toDecimalString()],
                ['label' => 'New Customers', 'value' => (string) $customers->count()],
            ],
            emptyMessage: 'No lending, registration or collection activity in this window.',
            reconciliation: 'Loans are counted in the month they were DISBURSED, not applied for — that is when the book grew, and it is where §6 puts the ledger entry. Revenue is collected payments, not billed amounts: an instalment nobody paid is not growth. The first month has no growth figure rather than a growth from zero, which would read as infinite.',
        );
    }

    /**
     * Month-on-month change as a percentage.
     *
     * An em dash when there is no previous month, and when the previous month
     * was zero — a percentage change from nothing is not a large number, it is
     * an undefined one, and printing "100%" would misdescribe a first sale as
     * a doubling.
     */
    private function growth(?Money $previous, Money $current): string
    {
        if ($previous === null || ! $previous->isPositive()) {
            return '—';
        }

        return $this->sources->percentageOf($current->subtract($previous), $previous);
    }
}
