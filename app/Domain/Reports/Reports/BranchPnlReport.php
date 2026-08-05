<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Services\ReportSources;
use App\Models\Branch;
use App\Support\Money;

/**
 * `GET /reports/branch-pnl` — income, expense and profit per branch, straight
 * from branch-tagged journal lines.
 *
 * §12 makes this "a simple filtered query": every branch, including Head
 * Office, runs through the same report because HQ is a branch record. The same
 * figures feed the commission engine's `BranchProfitCalculator`, so a manager
 * can read this report and see where their pool came from.
 */
final class BranchPnlReport implements Report
{
    public function __construct(private readonly ReportSources $sources) {}

    public function slug(): string
    {
        return 'branch-pnl';
    }

    public function title(): string
    {
        return 'Branch P&L';
    }

    public function description(): string
    {
        return 'Income, expense, and profit per branch, straight from branch-tagged journal lines.';
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
        $totalIncome = Money::zero();
        $totalExpense = Money::zero();

        foreach ($this->sources->branches($filters) as $branch) {
            [$income, $expense] = $this->figuresFor($branch, $filters);

            $rows[] = [
                'branch' => $branch->name,
                'type' => $this->branchType($branch),
                'income' => $income->toDecimalString(),
                'expense' => $expense->toDecimalString(),
                'profit' => $income->subtract($expense)->toDecimalString(),
            ];

            $totalIncome = $totalIncome->add($income);
            $totalExpense = $totalExpense->add($expense);
        }

        return new ReportResult(
            columns: [
                ReportColumn::text('branch', 'Branch'),
                ReportColumn::text('type', 'Type'),
                ReportColumn::money('income', 'Income'),
                ReportColumn::money('expense', 'Expense'),
                ReportColumn::money('profit', 'Profit'),
            ],
            rows: $rows,
            totals: [
                'branch' => 'Total',
                'type' => '',
                'income' => $totalIncome->toDecimalString(),
                'expense' => $totalExpense->toDecimalString(),
                'profit' => $totalIncome->subtract($totalExpense)->toDecimalString(),
            ],
            summary: [
                ['label' => 'Total Income', 'value' => $totalIncome->toDecimalString()],
                ['label' => 'Total Expense', 'value' => $totalExpense->toDecimalString()],
                ['label' => 'Profit', 'value' => $totalIncome->subtract($totalExpense)->toDecimalString()],
            ],
            emptyMessage: 'No branches match these filters.',
            reconciliation: 'Per-branch figures come from journal_entry_lines.branch_id and are the same ones BranchProfitCalculator feeds to the commission engine (§11). Entries posted without a branch — a capital injection, an HQ-level close — are in the system-wide trial balance but in no branch, so branch totals need not equal the system-wide income and expense.',
        );
    }

    /**
     * @return array{Money, Money}
     */
    private function figuresFor(Branch $branch, ReportFilters $filters): array
    {
        /*
         * The branch is fixed by the row, not by the caller's filter.
         *
         * `periodIncomeExpense` rather than the trial balance: a P&L asks what
         * the period earned. The trial balance is cumulative to a date, so it
         * answered "everything since inception up to the end of the window" —
         * `from` and `period` were accepted and then ignored. It also counts
         * the month-end close's sweep, which would report a closed period as
         * having earned nothing at all.
         */
        return $this->sources->periodIncomeExpense($filters->forBranch((int) $branch->getKey()));
    }

    private function branchType(Branch $branch): string
    {
        return match (true) {
            $branch->is_head_office => 'Head Office',
            $branch->type->value === 'sub' => 'Sub-branch',
            default => 'Main',
        };
    }
}
