<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Hr\Enums\StaffLoanStatus;
use App\Domain\Hr\Services\StaffLoanCalculator;
use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Support\Cell;
use App\Models\StaffLoan;
use App\Support\Money;

/**
 * `GET /reports/staff-loan` — §17's "Staff Loan Report".
 *
 * Every loan lent out of the Staff Fund, what has been recovered, and what is
 * still owed. Reads `staff_loans` and the one calculator payroll itself uses,
 * so the outstanding figure here is the same one the next payslip will recover
 * against.
 */
final class StaffLoanReport implements Report
{
    public function __construct(private readonly StaffLoanCalculator $calculator) {}

    public function slug(): string
    {
        return 'staff-loan';
    }

    public function title(): string
    {
        return 'Staff Loan';
    }

    public function description(): string
    {
        return 'Loans lent from the Staff Fund, what has been recovered, and what is still owed.';
    }

    public function group(): string
    {
        return 'HR';
    }

    public function supportedFilters(): array
    {
        return ['branchId'];
    }

    public function compute(ReportFilters $filters): ReportResult
    {
        $loans = StaffLoan::query()
            ->with(['staffProfile.user', 'staffProfile.branch'])
            ->when(
                $filters->branchId !== null,
                fn ($q) => $q->whereHas('staffProfile', fn ($s) => $s->where('branch_id', $filters->branchId)),
            )
            ->orderByDesc('id')
            ->get();

        $rows = $loans->map(fn (StaffLoan $loan): array => [
            'reference' => $loan->reference,
            'staff' => $loan->staffProfile->displayName(),
            'branch' => Cell::text($loan->staffProfile->branch?->name),
            'status' => $loan->status->value,
            'disbursed' => Cell::text($loan->disbursed_at?->toDateString()),
            'amount' => $loan->amount,
            'recovered' => $loan->amount_recovered,
            'outstanding' => $this->calculator->outstanding($loan)->toDecimalString(),
            'periods' => (string) $loan->recovery_periods,
        ])->all();

        $active = $loans->filter(fn (StaffLoan $l): bool => $l->status === StaffLoanStatus::Active);

        $lent = Money::sum($loans->map(fn (StaffLoan $l): Money => $l->amountMoney()));
        $recovered = Money::sum($loans->map(fn (StaffLoan $l): Money => $l->recoveredMoney()));
        $outstanding = Money::sum(
            $active->map(fn (StaffLoan $l): Money => $this->calculator->outstanding($l)),
        );

        return new ReportResult(
            columns: [
                ReportColumn::text('reference', 'Reference'),
                ReportColumn::text('staff', 'Staff'),
                ReportColumn::text('branch', 'Branch'),
                ReportColumn::text('status', 'Status'),
                ReportColumn::text('disbursed', 'Disbursed'),
                ReportColumn::money('amount', 'Amount'),
                ReportColumn::money('recovered', 'Recovered'),
                ReportColumn::money('outstanding', 'Outstanding'),
                ReportColumn::text('periods', 'Periods'),
            ],
            rows: $rows,
            totals: [
                'reference' => sprintf('%d loans', $loans->count()),
                'amount' => $lent->toDecimalString(),
                'recovered' => $recovered->toDecimalString(),
                'outstanding' => $outstanding->toDecimalString(),
            ],
            summary: [
                ['label' => 'Loans', 'value' => (string) $loans->count()],
                ['label' => 'Active', 'value' => (string) $active->count()],
                ['label' => 'Outstanding', 'value' => $outstanding->toDecimalString()],
            ],
            emptyMessage: 'No staff loans for these filters.',
            reconciliation: 'Outstanding across active loans equals the balance of 7010 Staff Loan Receivable. A closed loan reads zero outstanding: recovery is capped at what is owed and the loan closes on the instalment that clears it, so 7010 walks to zero and never below.',
        );
    }
}
