<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Support\Cell;
use App\Models\JournalEntry;
use App\Support\Money;

/**
 * `GET /reports/reversals` — every reversal entry posted, and the original it
 * mirrors.
 *
 * A compliance report in the strict sense: it exists so that someone can ask
 * "what has been undone, by whom, and why" and get an answer that cannot have
 * been tidied up, because §8 makes journal entries immutable.
 */
final class ReversalsReport implements Report
{
    public function slug(): string
    {
        return 'reversals';
    }

    public function title(): string
    {
        return 'Reversals';
    }

    public function description(): string
    {
        return 'Every reversal entry posted, and the original it mirrors.';
    }

    public function group(): string
    {
        return 'Compliance';
    }

    public function supportedFilters(): array
    {
        return ['from', 'to', 'period'];
    }

    public function compute(ReportFilters $filters): ReportResult
    {
        $reversals = JournalEntry::query()
            ->with(['reversedEntry', 'creator', 'lines'])
            ->where('is_reversal', true)
            ->orderByDesc('posted_at')
            ->get()
            ->filter(fn (JournalEntry $e): bool => ! $filters->hasDateWindow() || $filters->covers($e->entry_date))
            ->values();

        $rows = $reversals->map(fn (JournalEntry $entry): array => [
            'entryNumber' => $entry->entry_number,
            'entryDate' => $entry->entry_date->toDateString(),
            'reverses' => Cell::text($entry->reversedEntry?->entry_number),
            'originalDate' => Cell::text($entry->reversedEntry?->entry_date->toDateString()),
            'amount' => $entry->totalDebits()->toDecimalString(),
            'approvedBy' => Cell::text($entry->creator?->name),
            'description' => $entry->description,
        ])->all();

        $total = Money::sum($reversals->map(fn (JournalEntry $e): Money => $e->totalDebits()));

        return new ReportResult(
            columns: [
                ReportColumn::text('entryNumber', 'Reversal Entry'),
                ReportColumn::text('entryDate', 'Posted'),
                ReportColumn::text('reverses', 'Reverses'),
                ReportColumn::text('originalDate', 'Original Date'),
                ReportColumn::money('amount', 'Amount'),
                ReportColumn::text('approvedBy', 'Approved by'),
                ReportColumn::text('description', 'Reason'),
            ],
            rows: $rows,
            totals: ['entryNumber' => sprintf('%d reversals', count($rows)), 'amount' => $total->toDecimalString()],
            summary: [
                ['label' => 'Reversals', 'value' => (string) count($rows)],
                ['label' => 'Value Reversed', 'value' => $total->toDecimalString()],
            ],
            emptyMessage: 'No reversals have been posted.',
            reconciliation: 'Each row is a real journal entry with is_reversal = true. The original is left untouched (§5), which is why both appear in the trial balance and net to zero — a reversal removes the effect of an entry without removing the record of it.',
        );
    }
}
