<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Services\ReportSources;
use App\Models\CustomerCategory;
use App\Models\Loan;
use App\Support\Money;

/**
 * `GET /reports/segmentation` — portfolio and customer counts by customer
 * category.
 *
 * Categories are the lens because §2.3 makes them the risk-bearing entity: a
 * category carries the risk tier, the KYC requirements and the products a
 * borrower may take. Segmenting by anything else would describe the book
 * without describing its risk.
 */
final class SegmentationReport implements Report
{
    public function __construct(private readonly ReportSources $sources) {}

    public function slug(): string
    {
        return 'segmentation';
    }

    public function title(): string
    {
        return 'Customer Segmentation';
    }

    public function description(): string
    {
        return 'Portfolio and customer counts by customer category.';
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
        $rows = [];

        $customerTotal = 0;
        $loanTotal = 0;
        $outstandingTotal = Money::zero();

        foreach (CustomerCategory::query()->orderBy('name')->get() as $category) {
            $categoryLoans = $loans->filter(
                fn (Loan $l): bool => $l->customer?->customer_category_id === $category->getKey(),
            );

            $outstanding = Money::sum($categoryLoans->map(fn (Loan $l): Money => $this->sources->loanOutstanding($l)));
            $customers = $categoryLoans->pluck('customer_id')->unique()->count();

            $rows[] = [
                'category' => $category->name,
                'riskTier' => $category->risk_tier->value,
                'sector' => $category->sector->value,
                'customers' => $customers,
                'loans' => $categoryLoans->count(),
                'outstanding' => $outstanding->toDecimalString(),

                // Per loan rather than per customer: a borrower with two loans
                // is one customer but two exposures, and the average is a
                // statement about exposure size.
                'avgLoan' => $categoryLoans->isEmpty()
                    ? '0.00'
                    : $outstanding->divide($categoryLoans->count())->toDecimalString(),
            ];

            $customerTotal += $customers;
            $loanTotal += $categoryLoans->count();
            $outstandingTotal = $outstandingTotal->add($outstanding);
        }

        return new ReportResult(
            columns: [
                ReportColumn::text('category', 'Category'),
                ReportColumn::text('riskTier', 'Risk Tier'),
                ReportColumn::text('sector', 'Sector'),
                ReportColumn::number('customers', 'Customers'),
                ReportColumn::number('loans', 'Loans'),
                ReportColumn::money('outstanding', 'Outstanding'),
                ReportColumn::money('avgLoan', 'Avg Loan'),
            ],
            rows: $rows,
            totals: [
                'category' => 'Total',
                'customers' => $customerTotal,
                'loans' => $loanTotal,
                'outstanding' => $outstandingTotal->toDecimalString(),
            ],
            emptyMessage: 'No categories configured.',
            reconciliation: 'Outstanding sums to the same portfolio total as the Loan Portfolio report — both read loan_schedules through the one accessor layer.',
        );
    }
}
