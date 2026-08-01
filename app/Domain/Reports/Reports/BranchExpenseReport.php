<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Expenses\Enums\ExpenseRequestStatus;
use App\Domain\Expenses\Enums\ExpenseScope;
use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Support\Cell;
use App\Models\ExpenseRequest;
use App\Support\Money;

/**
 * `GET /reports/branch-expense` — §3C of the reports document.
 *
 *   > All expenses tagged to branch · HQ-paid but branch-tagged expenses ·
 *   > Expense categories (rent, fuel, etc.)
 *
 * All three of those are one question — *what did this branch spend, on what,
 * and who paid it* — so they are one report with a `paidBy` column rather than
 * three tables the reader has to reconcile.
 *
 * ## Approved only
 *
 * A pending request has not been decided and a rejected one never happened;
 * neither is an expense. Approval is also the moment the expense posts to the
 * ledger, so counting anything else would put a figure in an expense report
 * that no journal line supports.
 *
 * ## Why this reads the requests and not the ledger
 *
 * The category is on the request, not on the journal line, so the ledger alone
 * cannot tell rent from fuel.
 *
 * This total is **not** Branch P&L's Expense column and must not be read as
 * such. That column is every expense-type account for the branch — salary,
 * commission and bank charges included — and none of those is raised as an
 * expense request. What this total does tie to is the expense-category chart
 * accounts, which is the subset an expense request can post to.
 */
final class BranchExpenseReport implements Report
{
    public function slug(): string
    {
        return 'branch-expense';
    }

    public function title(): string
    {
        return 'Branch Expense';
    }

    public function description(): string
    {
        return 'Every approved expense tagged to a branch, by category, including those head office paid for.';
    }

    public function group(): string
    {
        return 'Branch';
    }

    public function supportedFilters(): array
    {
        return ['branchId', 'period', 'from', 'to'];
    }

    public function compute(ReportFilters $filters): ReportResult
    {
        $expenses = ExpenseRequest::query()
            // Eager-loaded: a hundred expenses would otherwise be three hundred
            // queries for the category, the branch and the decider.
            ->with(['category', 'branch', 'requester', 'decider'])
            ->where('status', ExpenseRequestStatus::Approved)
            ->whereNotNull('branch_id')
            ->when($filters->branchId !== null, fn ($q) => $q->where('branch_id', $filters->branchId))
            ->when($filters->from !== null, fn ($q) => $q->whereDate('decided_at', '>=', $filters->from))
            ->when($filters->to !== null, fn ($q) => $q->whereDate('decided_at', '<=', $filters->to))
            ->when(
                $filters->period !== null,
                fn ($q) => $q->whereRaw("DATE_FORMAT(decided_at, '%Y-%m') = ?", [$filters->period]),
            )
            ->orderByDesc('decided_at')
            ->get();

        $rows = $expenses->map(fn (ExpenseRequest $expense): array => [
            'reference' => $expense->reference,
            'date' => Cell::text($expense->decided_at?->toDateString()),
            'branch' => Cell::text($expense->branch?->name),
            'category' => Cell::text($expense->category?->name),

            /*
             * §3C's "HQ-paid but branch-tagged". A category's own scope says who
             * bears the cost centrally; the request's branch says who it is
             * charged to. When the two differ, head office paid for something a
             * branch consumed — which is precisely the case §3C asks to see.
             */
            'paidBy' => $expense->category?->scope === ExpenseScope::Headquarters
                ? 'Head Office'
                : 'Branch',

            'amount' => $expense->amount,
            'requestedBy' => Cell::text($expense->requester?->name),
            'approvedBy' => Cell::text($expense->decider?->name),
        ])->all();

        $total = Money::sum($expenses->map(fn (ExpenseRequest $e): Money => Money::of($e->amount)));

        $hqPaid = Money::sum(
            $expenses->filter(fn (ExpenseRequest $e): bool => $e->category?->scope === ExpenseScope::Headquarters)
                ->map(fn (ExpenseRequest $e): Money => Money::of($e->amount)),
        );

        return new ReportResult(
            columns: [
                ReportColumn::text('reference', 'Reference'),
                ReportColumn::text('date', 'Date'),
                ReportColumn::text('branch', 'Branch'),
                ReportColumn::text('category', 'Category'),
                ReportColumn::text('paidBy', 'Paid By'),
                ReportColumn::money('amount', 'Amount'),
                ReportColumn::text('requestedBy', 'Requested By'),
                ReportColumn::text('approvedBy', 'Approved By'),
            ],
            rows: $rows,
            totals: [
                'reference' => sprintf('%d expenses', $expenses->count()),
                'amount' => $total->toDecimalString(),
            ],
            summary: [
                ['label' => 'Total Spent', 'value' => $total->toDecimalString()],
                ['label' => 'Head Office Paid', 'value' => $hqPaid->toDecimalString()],
                ['label' => 'Categories', 'value' => (string) $expenses->pluck('expense_category_id')->unique()->count()],
            ],
            emptyMessage: 'No approved branch expenses for these filters.',
            reconciliation: 'Approved requests only — a pending one has not been decided and a rejected one never happened, and approval is the moment the expense posts. This total is a SUBSET of Branch P&L\'s Expense column, not equal to it: that column carries every expense-type account for the branch, including salary, commission and bank charges, none of which is raised as an expense request. What it does tie to is the debits on the expense-category chart accounts for the same branch and window, which is where an approved request posts.',
        );
    }
}
