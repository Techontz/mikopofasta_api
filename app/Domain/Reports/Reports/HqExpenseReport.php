<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Expenses\Enums\ExpenseRequestStatus;
use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Services\ReportSources;
use App\Models\ExpenseRequest;
use App\Support\Money;

/**
 * `GET /reports/hq-expense` — §4B of the reports document.
 *
 *   > HQ operational expenses · Comparison month-to-month
 *
 * The comparison is the point, so the report is **one row per month** rather
 * than one per expense: "what did head office spend in March" is only useful
 * beside February, and a list of individual expenses cannot be read that way.
 * The Branch Expense report is the itemised view; this is the trend.
 *
 * Each row carries the change on the month before it, which is the whole of
 * what §4B asks for and the thing a reader would otherwise work out with a
 * calculator.
 */
final class HqExpenseReport implements Report
{
    public function __construct(private readonly ReportSources $sources) {}

    public function slug(): string
    {
        return 'hq-expense';
    }

    public function title(): string
    {
        return 'HQ Expense';
    }

    public function description(): string
    {
        return 'Head office operational expenses by month, with the change on the month before.';
    }

    public function group(): string
    {
        return 'Financial';
    }

    /**
     * No `branchId`. This report IS head office — accepting a branch filter
     * would let a caller ask for "HQ expenses at Kakonko", which is not a
     * question with an answer.
     */
    public function supportedFilters(): array
    {
        return ['period', 'from', 'to'];
    }

    public function compute(ReportFilters $filters): ReportResult
    {
        $headOffice = $this->sources->headOffice();

        $expenses = ExpenseRequest::query()
            ->with('category')
            ->where('status', ExpenseRequestStatus::Approved)
            ->where(function ($q) use ($headOffice): void {
                /*
                 * Head office spending is either untagged — a company-wide cost
                 * belonging to no branch — or tagged to the head office branch
                 * record itself. Both are HQ's, and taking only one of them
                 * would understate the figure the document asks for.
                 */
                $q->whereNull('branch_id');

                if ($headOffice !== null) {
                    $q->orWhere('branch_id', $headOffice->getKey());
                }
            })
            ->when($filters->from !== null, fn ($q) => $q->whereDate('decided_at', '>=', $filters->from))
            ->when($filters->to !== null, fn ($q) => $q->whereDate('decided_at', '<=', $filters->to))
            ->when(
                $filters->period !== null,
                fn ($q) => $q->whereRaw("DATE_FORMAT(decided_at, '%Y-%m') = ?", [$filters->period]),
            )
            ->get();

        /** @var array<string, array{total: Money, count: int, categories: array<string, Money>}> $months */
        $months = [];

        foreach ($expenses as $expense) {
            $month = $expense->decided_at?->format('Y-m') ?? 'unknown';
            $amount = Money::of($expense->amount);
            // Not nullsafe: `expense_category_id` is NOT NULL and the relation
            // is `withTrashed()`, so a retired category still resolves.
            $category = $expense->category->name;

            $months[$month] ??= ['total' => Money::zero(), 'count' => 0, 'categories' => []];
            $months[$month]['total'] = $months[$month]['total']->add($amount);
            $months[$month]['count']++;
            $months[$month]['categories'][$category] =
                ($months[$month]['categories'][$category] ?? Money::zero())->add($amount);
        }

        // Chronological, so "the month before" is the row above.
        ksort($months);

        $rows = [];
        $previous = null;

        foreach ($months as $month => $figures) {
            $rows[] = [
                'month' => $month,
                'expenses' => (string) $figures['count'],
                'total' => $figures['total']->toDecimalString(),
                'change' => $previous === null
                    ? '—'
                    : $figures['total']->subtract($previous)->toDecimalString(),
                'changePct' => $previous === null || ! $previous->isPositive()
                    ? '—'
                    : $this->sources->percentageOf($figures['total']->subtract($previous), $previous),
                'topCategory' => $this->topCategory($figures['categories']),
            ];

            $previous = $figures['total'];
        }

        // Newest first for reading; the change was computed in date order above.
        $rows = array_reverse($rows);

        $total = Money::sum(array_map(
            static fn (array $f): Money => $f['total'],
            array_values($months),
        ));

        $average = $months === [] ? Money::zero() : $total->divide(count($months));

        return new ReportResult(
            columns: [
                ReportColumn::text('month', 'Month'),
                ReportColumn::number('expenses', 'Expenses'),
                ReportColumn::money('total', 'Total'),
                ReportColumn::money('change', 'Change'),
                ReportColumn::percent('changePct', 'Change %'),
                ReportColumn::text('topCategory', 'Largest Category'),
            ],
            rows: $rows,
            totals: [
                'month' => sprintf('%d months', count($months)),
                'total' => $total->toDecimalString(),
            ],
            summary: [
                ['label' => 'Total HQ Expense', 'value' => $total->toDecimalString()],
                ['label' => 'Monthly Average', 'value' => $average->toDecimalString()],
                ['label' => 'Months', 'value' => (string) count($months)],
            ],
            emptyMessage: 'No approved head office expenses for these filters.',
            reconciliation: 'Approved requests either carrying no branch — a company-wide cost belonging to none — or tagged to the head office branch record. Change is against the month above in date order, so the first month has none rather than a change from zero, which would read as infinite growth.',
        );
    }

    /** @param array<string, Money> $categories */
    private function topCategory(array $categories): string
    {
        if ($categories === []) {
            return '—';
        }

        $topName = '';
        $topAmount = Money::zero();

        foreach ($categories as $name => $amount) {
            if ($amount->greaterThan($topAmount)) {
                $topName = $name;
                $topAmount = $amount;
            }
        }

        return sprintf('%s (%s)', $topName, $topAmount->toDecimalString());
    }
}
