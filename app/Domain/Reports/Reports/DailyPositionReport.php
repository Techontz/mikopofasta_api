<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Services\ReportSources;
use App\Models\ChartOfAccount;
use App\Models\JournalEntryLine;
use App\Support\Money;

/**
 * `GET /reports/daily-position` — §9C of the reports document.
 *
 *   > Cash in · Cash out · Net position
 *
 * One row per day, and a **running balance** down the page. The document's
 * Master Cashflow section asks for exactly that shape — *"Running balance
 * (after each transaction)"* — and a day without one is three numbers a reader
 * has to add up themselves to answer "where did we end up".
 *
 * ## The opening balance
 *
 * The running balance starts from what the cash accounts held *before* the
 * window, not from zero. A report that opened at zero would show a closing
 * balance that is the period's movement rather than the position — the same
 * figure as Net, and wrong wherever it mattered.
 *
 * That opening figure is computed by asking the ledger for every cash line
 * before the window: one aggregate, not a replay.
 */
final class DailyPositionReport implements Report
{
    public function __construct(private readonly ReportSources $sources) {}

    public function slug(): string
    {
        return 'daily-position';
    }

    public function title(): string
    {
        return 'Daily Position';
    }

    public function description(): string
    {
        return 'Cash in, cash out and the running balance, day by day.';
    }

    public function group(): string
    {
        return 'Collections';
    }

    public function supportedFilters(): array
    {
        return ['branchId', 'period', 'from', 'to'];
    }

    public function compute(ReportFilters $filters): ReportResult
    {
        $cashIds = $this->cashAccountIds();

        $lines = $this->sources->journalLines($filters)
            ->filter(fn (JournalEntryLine $l): bool => in_array($l->account_id, $cashIds, true));

        /** @var array<string, array{in: Money, out: Money, entries: int}> $byDay */
        $byDay = [];

        foreach ($lines as $line) {
            $day = $line->entry?->entry_date?->toDateString();

            if ($day === null) {
                continue;
            }

            $byDay[$day] ??= ['in' => Money::zero(), 'out' => Money::zero(), 'entries' => 0];
            $byDay[$day]['in'] = $byDay[$day]['in']->add($line->debitAmount());
            $byDay[$day]['out'] = $byDay[$day]['out']->add($line->creditAmount());
            $byDay[$day]['entries']++;
        }

        // Ascending, because a running balance only makes sense forwards.
        ksort($byDay);

        $balance = $this->openingBalance($filters, $cashIds, array_key_first($byDay));

        $opening = $balance;
        $rows = [];
        $totalIn = Money::zero();
        $totalOut = Money::zero();

        foreach ($byDay as $day => $figures) {
            $net = $figures['in']->subtract($figures['out']);
            $balance = $balance->add($net);

            $rows[] = [
                'day' => $day,
                'movements' => (string) $figures['entries'],
                'cashIn' => $figures['in']->toDecimalString(),
                'cashOut' => $figures['out']->toDecimalString(),
                'net' => $net->toDecimalString(),
                'balance' => $balance->toDecimalString(),
            ];

            $totalIn = $totalIn->add($figures['in']);
            $totalOut = $totalOut->add($figures['out']);
        }

        return new ReportResult(
            columns: [
                ReportColumn::text('day', 'Date'),
                ReportColumn::number('movements', 'Movements'),
                ReportColumn::money('cashIn', 'Cash In'),
                ReportColumn::money('cashOut', 'Cash Out'),
                ReportColumn::money('net', 'Net'),
                ReportColumn::money('balance', 'Running Balance'),
            ],
            rows: $rows,
            totals: [
                'day' => sprintf('%d days', count($rows)),
                'cashIn' => $totalIn->toDecimalString(),
                'cashOut' => $totalOut->toDecimalString(),
                'net' => $totalIn->subtract($totalOut)->toDecimalString(),
                'balance' => $balance->toDecimalString(),
            ],
            summary: [
                ['label' => 'Opening Balance', 'value' => $opening->toDecimalString()],
                ['label' => 'Cash In', 'value' => $totalIn->toDecimalString()],
                ['label' => 'Cash Out', 'value' => $totalOut->toDecimalString()],
                ['label' => 'Closing Balance', 'value' => $balance->toDecimalString()],
            ],
            emptyMessage: 'No cash moved on any day in this window.',
            reconciliation: 'Cash In and Cash Out are debits and credits on the non-system asset accounts — the bank accounts and teller floats, the same definition Cashflow and Cash Position use. The running balance opens at what those accounts held before the window rather than at zero, so the closing figure is a position and not merely the period movement; it ties to Cash Position\'s Available Cash for the same date and branch.',
        );
    }

    /** @return list<int> */
    private function cashAccountIds(): array
    {
        /** @var list<int> $ids */
        $ids = ChartOfAccount::query()
            ->where('type', AccountType::Asset)
            ->where('is_system', false)
            ->pluck('id')
            ->all();

        return $ids;
    }

    /**
     * What the cash accounts held before the first day shown.
     *
     * One aggregate over everything earlier, rather than replaying the ledger:
     * the opening balance is a sum, and computing it as one is what keeps this
     * report a single extra query regardless of how much history exists.
     *
     * @param list<int> $cashIds
     */
    private function openingBalance(ReportFilters $filters, array $cashIds, ?string $firstDay): Money
    {
        $before = $filters->from ?? $firstDay;

        if ($before === null || $cashIds === []) {
            return Money::zero();
        }

        $row = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->whereIn('journal_entry_lines.account_id', $cashIds)
            ->when(
                $filters->branchId !== null,
                fn ($q) => $q->where('journal_entry_lines.branch_id', $filters->branchId),
            )
            ->whereDate('journal_entries.entry_date', '<', $before)
            ->toBase()
            ->selectRaw('COALESCE(SUM(debit_amount), 0) d, COALESCE(SUM(credit_amount), 0) c')
            ->first();

        return Money::of((string) ($row->d ?? '0'))->subtract(Money::of((string) ($row->c ?? '0')));
    }
}
