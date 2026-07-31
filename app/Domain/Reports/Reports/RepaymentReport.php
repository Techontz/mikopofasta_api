<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Services\ReportSources;
use App\Domain\Reports\Support\Cell;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Support\Money;

/**
 * `GET /reports/repayment` — every payment collected, with its
 * Penalty/Interest/Principal split.
 *
 * The splits come from `payment_allocations`, written by the one allocation
 * core (§7) — this report does not re-derive them. Re-deriving would be a
 * second answer to "how much of this payment cleared the customer's interest",
 * and the whole design of Phase 6 was to have exactly one.
 */
final class RepaymentReport implements Report
{
    public function __construct(private readonly ReportSources $sources) {}

    public function slug(): string
    {
        return 'repayment';
    }

    public function title(): string
    {
        return 'Repayments';
    }

    public function description(): string
    {
        return 'Every payment received, with its Penalty/Interest/Principal split.';
    }

    public function group(): string
    {
        return 'Collections';
    }

    public function supportedFilters(): array
    {
        return ['branchId', 'from', 'to', 'period'];
    }

    public function compute(ReportFilters $filters): ReportResult
    {
        $payments = $this->sources->collectedPayments($filters);

        $rows = $payments->map(function (Payment $payment): array {
            $penalty = Money::sum($payment->allocations->map(fn (PaymentAllocation $a): Money => Money::of($a->penalty_allocated)));
            $interest = Money::sum($payment->allocations->map(fn (PaymentAllocation $a): Money => Money::of($a->interest_allocated)));
            $principal = Money::sum($payment->allocations->map(fn (PaymentAllocation $a): Money => Money::of($a->principal_allocated)));

            return [
                'reference' => $payment->payment_reference,
                'receivedAt' => $payment->received_at->toDateString(),
                'loanNumber' => Cell::text($payment->loan?->loan_number),
                'customer' => Cell::text($payment->customer?->fullName()),
                'branch' => Cell::text($payment->loan?->branch?->name),
                'channel' => str_replace('_', ' ', $payment->channel->value),
                'status' => $payment->status->value,
                'penalty' => $penalty->toDecimalString(),
                'interest' => $interest->toDecimalString(),
                'principal' => $principal->toDecimalString(),
                'amount' => $payment->amount,
            ];
        })->all();

        $sum = fn (string $key): Money => Money::sum(array_map(
            static fn (array $r): Money => Money::of((string) $r[$key]),
            $rows,
        ));

        $penalty = $sum('penalty');
        $interest = $sum('interest');
        $principal = $sum('principal');
        $amount = $sum('amount');

        return new ReportResult(
            columns: [
                ReportColumn::text('reference', 'Reference'),
                ReportColumn::text('receivedAt', 'Received'),
                ReportColumn::text('loanNumber', 'Loan #'),
                ReportColumn::text('customer', 'Customer'),
                ReportColumn::text('branch', 'Branch'),
                ReportColumn::text('channel', 'Channel'),
                ReportColumn::text('status', 'Status'),
                ReportColumn::money('penalty', 'Penalty'),
                ReportColumn::money('interest', 'Interest'),
                ReportColumn::money('principal', 'Principal'),
                ReportColumn::money('amount', 'Amount'),
            ],
            rows: $rows,
            totals: [
                'reference' => sprintf('%d payments', count($rows)),
                'penalty' => $penalty->toDecimalString(),
                'interest' => $interest->toDecimalString(),
                'principal' => $principal->toDecimalString(),
                'amount' => $amount->toDecimalString(),
            ],
            summary: [
                ['label' => 'Collected', 'value' => $amount->toDecimalString()],
                ['label' => 'To Penalty', 'value' => $penalty->toDecimalString()],
                ['label' => 'To Interest', 'value' => $interest->toDecimalString()],
                ['label' => 'To Principal', 'value' => $principal->toDecimalString()],
            ],
            emptyMessage: 'No collected payments in this window.',
            reconciliation: 'Splits come from payment_allocations, written by the one allocation core (§7). The allocated total can be less than the amount received when part of a payment was an overpayment with nothing left to clear. Every payment listed carries a journal entry, so the amount column ties to the debits posted to cash and bank accounts.',
        );
    }
}
