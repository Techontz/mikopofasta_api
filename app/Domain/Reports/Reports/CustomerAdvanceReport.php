<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\CustomerAdvances\Services\CustomerAdvanceCalculator;
use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Support\Cell;
use App\Models\CustomerAdvancePayment;
use App\Support\Money;

/**
 * `GET /reports/customer-advance` — every customer salary advance collection.
 *
 * This is the "transaction history" half of the client's rule. The dashboard's
 * Branch List shows one month's total and nothing else; they were explicit that
 * "the detailed transaction history remains available in the separate
 * Reports/Transactions section", and this is that section.
 *
 * ## Why it reports PAYMENTS rather than advances
 *
 * The figure people come here to explain is a monthly collections total, and an
 * advance cannot answer that: one advance is paid across several months and one
 * month contains payments from several advances. A row per payment is the only
 * grain at which the report and the dashboard count the same events.
 *
 * Each row carries its own split, because that is the question this report
 * exists to settle: how much of what a customer paid went back to operational
 * principal, and how much the business actually earned.
 */
final class CustomerAdvanceReport implements Report
{
    public function __construct(private readonly CustomerAdvanceCalculator $calculator) {}

    public function slug(): string
    {
        return 'customer-advance';
    }

    public function title(): string
    {
        return 'Customer Salary Advance';
    }

    public function description(): string
    {
        return 'Every salary advance collection, split into capital returned and profit earned.';
    }

    /**
     * Collections, not HR.
     *
     * The frontend defines six groups and this report answers a collections
     * question — what came in, and what of it was profit. Filing it beside the
     * staff advance under HR would put it in front of the wrong readers and
     * hide it from the people reconciling a month's takings.
     */
    public function group(): string
    {
        return 'Collections';
    }

    public function supportedFilters(): array
    {
        return ['branchId', 'from', 'to'];
    }

    public function compute(ReportFilters $filters): ReportResult
    {
        $payments = CustomerAdvancePayment::query()
            ->notReversed()
            ->with(['advance.customer', 'advance.branch', 'advance.category'])
            ->when($filters->branchId !== null, fn ($q) => $q->where('branch_id', $filters->branchId))
            ->when($filters->from !== null, fn ($q) => $q->whereDate('paid_at', '>=', $filters->from))
            ->when($filters->to !== null, fn ($q) => $q->whereDate('paid_at', '<=', $filters->to))
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->get();

        $rows = $payments->map(fn (CustomerAdvancePayment $payment): array => [
            'date' => $payment->paid_at->toDateString(),
            'reference' => $payment->reference,
            'advance' => Cell::text($payment->advance?->reference),
            'customer' => Cell::text($payment->advance?->customer?->fullName()),
            'branch' => Cell::text($payment->advance?->branch?->name),
            'channel' => $payment->channel,
            'amount' => $payment->amount,
            'principal' => $payment->principal_portion,
            'profit' => $payment->profitMoney()->toDecimalString(),
            'outstanding' => $payment->advance === null
                ? '0.00'
                : $this->calculator->outstanding($payment->advance)->toDecimalString(),
        ])->all();

        $sum = fn (callable $get): Money => Money::sum($payments->map($get));

        $collected = $sum(fn (CustomerAdvancePayment $p): Money => $p->amountMoney());
        $capital = $sum(fn (CustomerAdvancePayment $p): Money => $p->principalMoney());
        $profit = $sum(fn (CustomerAdvancePayment $p): Money => $p->profitMoney());

        return new ReportResult(
            columns: [
                ReportColumn::text('date', 'Date'),
                ReportColumn::text('reference', 'Payment'),
                ReportColumn::text('advance', 'Advance'),
                ReportColumn::text('customer', 'Customer'),
                ReportColumn::text('branch', 'Branch'),
                ReportColumn::text('channel', 'Channel'),
                ReportColumn::money('amount', 'Collected'),
                ReportColumn::money('principal', 'To Principal'),
                ReportColumn::money('profit', 'To Income'),
                ReportColumn::money('outstanding', 'Still Owed'),
            ],
            rows: $rows,
            totals: [
                'date' => sprintf('%d payments', $payments->count()),
                'amount' => $collected->toDecimalString(),
                'principal' => $capital->toDecimalString(),
                'profit' => $profit->toDecimalString(),
            ],
            summary: [
                ['label' => 'Collected', 'value' => $collected->toDecimalString()],
                ['label' => 'Back to principal', 'value' => $capital->toDecimalString()],
                ['label' => 'Profit earned', 'value' => $profit->toDecimalString()],
            ],
            emptyMessage: 'No salary advance collections for these filters.',
            reconciliation: 'Collected equals the debits this module posted to the funding accounts over the period. To Principal is the credit to 1250 Salary Advance Receivable; To Income is the credit to 2000 Interest Income plus 2100 Fee Income. Nothing is held under Salary Advance itself — there is no such account, by design.',
        );
    }
}
