<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Repayments\Enums\SuspenseStatus;
use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Services\ReportSources;
use App\Domain\Reports\Support\Cell;
use App\Models\SuspenseItem;
use App\Support\Money;

/**
 * `GET /reports/suspense` — money received that could not be matched to a loan.
 *
 * The report that must reconcile most exactly: open suspense items should
 * equal the Suspense Account balance in the trial balance, because §5 ledgers
 * unmatched money on arrival (Dr Cash · Cr Suspense) and draws it back down on
 * resolution with a second entry. The report computes both figures and states
 * whether they agree, rather than asserting that they do.
 */
final class SuspenseReport implements Report
{
    public function __construct(private readonly ReportSources $sources) {}

    public function slug(): string
    {
        return 'suspense';
    }

    public function title(): string
    {
        return 'Suspense';
    }

    public function description(): string
    {
        return 'Money received that could not be matched to a loan.';
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
        $items = SuspenseItem::query()
            ->with(['payment', 'resolver'])
            ->orderByDesc('id')
            ->get()
            ->filter(fn (SuspenseItem $i): bool => ! $filters->hasDateWindow()
                || $filters->covers($i->payment?->received_at))
            ->values();

        $rows = $items->map(fn (SuspenseItem $item): array => [
            'reference' => Cell::text($item->payment?->payment_reference),
            'receivedAt' => Cell::text($item->payment?->received_at->toDateString()),
            'reason' => $item->reason,
            'status' => $item->status->value,
            'resolvedBy' => Cell::text($item->resolver?->name),
            'amount' => $item->amount,
        ])->all();

        $open = $items->filter(fn (SuspenseItem $i): bool => $i->status !== SuspenseStatus::Allocated);
        $openAmount = Money::sum($open->map(fn (SuspenseItem $i): Money => Money::of($i->amount)));

        return new ReportResult(
            columns: [
                ReportColumn::text('reference', 'Payment'),
                ReportColumn::text('receivedAt', 'Received'),
                ReportColumn::text('reason', 'Reason'),
                ReportColumn::text('status', 'Status'),
                ReportColumn::text('resolvedBy', 'Handled by'),
                ReportColumn::money('amount', 'Amount'),
            ],
            rows: $rows,
            totals: [
                'reference' => sprintf('%d items', count($rows)),
                'amount' => Money::sum($items->map(fn (SuspenseItem $i): Money => Money::of($i->amount)))->toDecimalString(),
            ],
            summary: [
                ['label' => 'Open Items', 'value' => (string) $open->count()],
                ['label' => 'Open Amount', 'value' => $openAmount->toDecimalString()],
            ],
            emptyMessage: 'Suspense is clear.',
            reconciliation: $this->reconciliationNote($openAmount, $filters),
        );
    }

    private function reconciliationNote(Money $openAmount, ReportFilters $filters): string
    {
        $ledgerBalance = collect($this->sources->trialBalance($filters->forBranch(null))['rows'])
            ->firstWhere('code', SystemAccountCode::Suspense->value);

        $balance = Money::of((string) ($ledgerBalance['balance'] ?? '0.00'));

        $verdict = $balance->equals($openAmount)
            ? 'These agree.'
            : sprintf('These DIFFER by %s — investigate.', $balance->subtract($openAmount)->toDecimalString());

        return sprintf(
            'Open items total %s; the Suspense Account balance in the ledger is %s. %s Resolved items were cleared by a second journal entry (Dr Suspense · Cr Loan), never by editing the original — which is why both entries remain in the trial balance and net to zero.',
            $openAmount->toDecimalString(),
            $balance->toDecimalString(),
            $verdict,
        );
    }
}
