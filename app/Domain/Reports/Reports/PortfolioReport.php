<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Services\ReportSources;
use App\Domain\Reports\Support\Cell;
use App\Models\Loan;
use App\Support\Money;

/**
 * `GET /reports/portfolio` — every loan with money out, its balance, and how
 * much has been repaid.
 *
 * The one report the whole Portfolio group ties back to: Age Analysis and
 * Segmentation both sum to the same outstanding total, because all three ask
 * ReportSources the same question.
 */
final class PortfolioReport implements Report
{
    public function __construct(private readonly ReportSources $sources) {}

    public function slug(): string
    {
        return 'portfolio';
    }

    public function title(): string
    {
        return 'Loan Portfolio';
    }

    public function description(): string
    {
        return 'Every loan with money out, its balance, and how much has been repaid.';
    }

    public function group(): string
    {
        return 'Portfolio';
    }

    public function supportedFilters(): array
    {
        return ['branchId'];
    }

    public function compute(ReportFilters $filters): ReportResult
    {
        $loans = $this->sources->openBookLoans($filters);

        $rows = $loans->map(function (Loan $loan): array {
            $figures = $this->sources->loanFigures($loan);

            return [
                'loanNumber' => $loan->loan_number,
                'customer' => Cell::text($loan->customer?->fullName()),
                'branch' => Cell::text($loan->branch?->name),
                'product' => Cell::text($loan->product?->name),
                'status' => $loan->status->label(),
                'principal' => $figures['principal']->toDecimalString(),
                'totalDue' => $figures['due']->toDecimalString(),
                'paid' => $figures['paid']->toDecimalString(),
                'outstanding' => $figures['outstanding']->toDecimalString(),
            ];
        })->all();

        $principal = Money::sum($loans->map(fn (Loan $l): Money => $l->principal()));
        $outstanding = Money::sum($loans->map(fn (Loan $l): Money => $this->sources->loanOutstanding($l)));
        $paid = Money::sum($loans->map(fn (Loan $l): Money => $this->sources->loanPaid($l)));

        return new ReportResult(
            columns: [
                ReportColumn::text('loanNumber', 'Loan #'),
                ReportColumn::text('customer', 'Customer'),
                ReportColumn::text('branch', 'Branch'),
                ReportColumn::text('product', 'Product'),
                ReportColumn::text('status', 'Status'),
                ReportColumn::money('principal', 'Principal'),
                ReportColumn::money('totalDue', 'Total Due'),
                ReportColumn::money('paid', 'Paid'),
                ReportColumn::money('outstanding', 'Outstanding'),
            ],
            rows: $rows,
            totals: [
                'loanNumber' => sprintf('%d loans', count($rows)),
                'principal' => $principal->toDecimalString(),
                'paid' => $paid->toDecimalString(),
                'outstanding' => $outstanding->toDecimalString(),
            ],
            summary: [
                ['label' => 'Active Loans', 'value' => (string) count($rows)],
                ['label' => 'Principal Disbursed', 'value' => $principal->toDecimalString()],
                ['label' => 'Collected', 'value' => $paid->toDecimalString()],
                ['label' => 'Outstanding', 'value' => $outstanding->toDecimalString()],
            ],
            emptyMessage: 'No disbursed loans match these filters.',
            reconciliation: 'Outstanding is summed from loan_schedules for disbursed loans only — the same rule the Loans module applies, so this ties to the Loan Receivable account net of repayments.',
        );
    }
}
