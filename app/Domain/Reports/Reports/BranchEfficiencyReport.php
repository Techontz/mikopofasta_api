<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Services\ReportSources;
use App\Models\StaffProfile;

/**
 * `GET /reports/branch-efficiency` — cost-to-income ratio and profit per staff
 * member, per branch.
 *
 * Two ratios over the same ledger figures the Branch P&L shows, plus the head
 * count from `staff_profiles`. Profit per head is what makes a small
 * profitable branch comparable with a large one.
 */
final class BranchEfficiencyReport implements Report
{
    public function __construct(private readonly ReportSources $sources) {}

    public function slug(): string
    {
        return 'branch-efficiency';
    }

    public function title(): string
    {
        return 'Branch Efficiency';
    }

    public function description(): string
    {
        return 'Cost-to-income ratio and profit per staff member, per branch.';
    }

    public function group(): string
    {
        return 'Branch';
    }

    public function supportedFilters(): array
    {
        return ['branchId', 'from', 'to', 'period'];
    }

    public function compute(ReportFilters $filters): ReportResult
    {
        $rows = [];

        foreach ($this->sources->branches($filters) as $branch) {
            // Period-scoped, and excluding the month-end close's sweep of
            // income into Profit — see ReportSources::periodIncomeExpense().
            [$income, $expense] = $this->sources->periodIncomeExpense(
                $filters->forBranch((int) $branch->getKey()),
            );

            $profit = $income->subtract($expense);

            $headcount = StaffProfile::query()
                ->where('branch_id', $branch->getKey())
                ->where('employment_status', 'active')
                ->count();

            $rows[] = [
                'branch' => $branch->name,
                'staff' => $headcount,
                'income' => $income->toDecimalString(),
                'expense' => $expense->toDecimalString(),
                'profit' => $profit->toDecimalString(),
                'costToIncome' => $this->sources->percentageOf($expense, $income),
                'margin' => $this->sources->percentageOf($profit, $income),

                // Zero staff means the ratio is undefined rather than infinite;
                // reporting zero says "no basis to judge", which is honest.
                'profitPerStaff' => $headcount > 0 ? $profit->divide($headcount)->toDecimalString() : '0.00',
            ];
        }

        return new ReportResult(
            columns: [
                ReportColumn::text('branch', 'Branch'),
                ReportColumn::number('staff', 'Staff'),
                ReportColumn::money('income', 'Income'),
                ReportColumn::money('expense', 'Expense'),
                ReportColumn::money('profit', 'Profit'),
                ReportColumn::percent('costToIncome', 'Cost/Income'),
                ReportColumn::percent('margin', 'Margin'),
                ReportColumn::money('profitPerStaff', 'Profit / Staff'),
            ],
            rows: $rows,
            emptyMessage: 'No branches match these filters.',
            reconciliation: 'Ratios are derived from the same branch-tagged ledger figures as Branch P&L; head count is the active staff_profiles assigned to the branch.',
        );
    }
}
