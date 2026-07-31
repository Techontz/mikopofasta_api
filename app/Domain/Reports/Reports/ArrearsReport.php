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
 * `GET /reports/arrears` — loans with at least one overdue installment, aged
 * by days past due.
 *
 * Sorted worst-first, because the point of the report is who to call today.
 */
final class ArrearsReport implements Report
{
    public function __construct(private readonly ReportSources $sources) {}

    public function slug(): string
    {
        return 'arrears';
    }

    public function title(): string
    {
        return 'Arrears';
    }

    public function description(): string
    {
        return 'Loans with at least one overdue installment, aged by days past due.';
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
        $delinquent = $this->sources->openBookLoans($filters)
            ->map(fn (Loan $loan): array => [
                'loan' => $loan,
                'dpd' => $this->sources->daysPastDue($loan),
                'overdue' => $this->sources->loanOverdue($loan),
            ])
            ->filter(fn (array $x): bool => $x['overdue']->isPositive())
            ->sortByDesc('dpd')
            ->values();

        $rows = $delinquent->map(function (array $x): array {
            /** @var Loan $loan */
            $loan = $x['loan'];

            return [
                'loanNumber' => $loan->loan_number,
                'customer' => Cell::text($loan->customer?->fullName()),
                'branch' => Cell::text($loan->branch?->name),
                'status' => $loan->status->label(),
                'daysPastDue' => $x['dpd'],
                'bucket' => $this->sources->bucketFor($x['dpd'])->label(),
                'overdue' => $x['overdue']->toDecimalString(),
                'outstanding' => $this->sources->loanOutstanding($loan)->toDecimalString(),
            ];
        })->all();

        $overdue = Money::sum($delinquent->map(fn (array $x): Money => $x['overdue']));
        $outstanding = Money::sum($delinquent->map(fn (array $x): Money => $this->sources->loanOutstanding($x['loan'])));

        return new ReportResult(
            columns: [
                ReportColumn::text('loanNumber', 'Loan #'),
                ReportColumn::text('customer', 'Customer'),
                ReportColumn::text('branch', 'Branch'),
                ReportColumn::text('status', 'Status'),
                ReportColumn::number('daysPastDue', 'DPD'),
                ReportColumn::text('bucket', 'Bucket'),
                ReportColumn::money('overdue', 'Overdue'),
                ReportColumn::money('outstanding', 'Outstanding'),
            ],
            rows: $rows,
            totals: [
                'loanNumber' => sprintf('%d loans', count($rows)),
                'overdue' => $overdue->toDecimalString(),
                'outstanding' => $outstanding->toDecimalString(),
            ],
            summary: [
                ['label' => 'Loans in Arrears', 'value' => (string) count($rows)],
                ['label' => 'Overdue Amount', 'value' => $overdue->toDecimalString()],
            ],
            emptyMessage: 'No loans are in arrears for these filters.',
            reconciliation: 'Overdue is the unpaid portion of installments past their due date, read from loan_schedules — including penalties accrued by the overdue run. Those accrued penalties are deliberately NOT a ledger balance (see OSC-1: penalty income is recognised on collection), so this figure is larger than anything the trial balance carries.',
        );
    }
}
