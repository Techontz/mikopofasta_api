<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Services\ReportSources;
use App\Domain\Reports\Support\Cell;
use App\Models\Loan;
use App\Support\Money;

/**
 * `GET /reports/recovery` — defaulted, written-off and recovered loans.
 *
 * The loans behind the Write-Off Expense and Recovered Loans account balances.
 * Note that those postings are not yet built (see the Phase 6 notes), so this
 * report currently lists the loans in those states without a ledger balance to
 * tie to — which is the honest position, and stated in the reconciliation
 * rather than papered over.
 */
final class RecoveryReport implements Report
{
    public function __construct(private readonly ReportSources $sources) {}

    public function slug(): string
    {
        return 'recovery';
    }

    public function title(): string
    {
        return 'Recovery';
    }

    public function description(): string
    {
        return 'Defaulted, written-off, and recovered loans.';
    }

    public function group(): string
    {
        return 'Collections';
    }

    public function supportedFilters(): array
    {
        return ['branchId'];
    }

    public function compute(ReportFilters $filters): ReportResult
    {
        $loans = $this->sources->liveLoans($filters)
            ->filter(fn (Loan $l): bool => $this->sources->isRecoveryStatus($l->status))
            ->values();

        $rows = $loans->map(fn (Loan $loan): array => [
            'loanNumber' => $loan->loan_number,
            'customer' => Cell::text($loan->customer?->fullName()),
            'branch' => Cell::text($loan->branch?->name),
            'status' => $loan->status->label(),
            'disbursedOn' => Cell::text($loan->disbursement_date?->toDateString()),
            'principal' => $loan->principal()->toDecimalString(),
            'paid' => $this->sources->loanPaid($loan)->toDecimalString(),
            'outstanding' => $this->sources->loanOutstanding($loan)->toDecimalString(),
        ])->all();

        return new ReportResult(
            columns: [
                ReportColumn::text('loanNumber', 'Loan #'),
                ReportColumn::text('customer', 'Customer'),
                ReportColumn::text('branch', 'Branch'),
                ReportColumn::text('status', 'Status'),
                ReportColumn::text('disbursedOn', 'Disbursed'),
                ReportColumn::money('principal', 'Principal'),
                ReportColumn::money('paid', 'Recovered'),
                ReportColumn::money('outstanding', 'Outstanding'),
            ],
            rows: $rows,
            totals: [
                'loanNumber' => sprintf('%d loans', count($rows)),
                'principal' => Money::sum($loans->map(fn (Loan $l): Money => $l->principal()))->toDecimalString(),
                'paid' => Money::sum($loans->map(fn (Loan $l): Money => $this->sources->loanPaid($l)))->toDecimalString(),
                'outstanding' => Money::sum($loans->map(fn (Loan $l): Money => $this->sources->loanOutstanding($l)))->toDecimalString(),
            ],
            emptyMessage: 'No defaulted, written-off, or recovered loans.',
            reconciliation: 'Write-offs and recoveries post to the Write-Off Expense and Recovered Loans accounts (§5); this report lists the loans behind those balances. Those postings are not yet implemented — see the Phase 6 notes — so today this report describes loan states rather than ledger balances.',
        );
    }
}
