<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Support\Cell;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Support\Money;

/**
 * `GET /reports/payroll` — payroll runs and what each staff member was paid.
 *
 * Reads `payroll_lines`, which is where the payroll engine wrote its answer —
 * it does not recompute a payslip. Recomputing would be a second implementation
 * of §11's arithmetic, and a report that disagreed with the payslip an employee
 * was handed would be worse than no report.
 */
final class PayrollReport implements Report
{
    public function slug(): string
    {
        return 'payroll';
    }

    public function title(): string
    {
        return 'Payroll';
    }

    public function description(): string
    {
        return 'Payroll runs and what each staff member was paid.';
    }

    public function group(): string
    {
        return 'HR';
    }

    /**
     * Branch is honoured through the staff member's posting, so a branch
     * manager sees their own team's payroll rather than the whole company's.
     */
    public function supportedFilters(): array
    {
        return ['branchId', 'period'];
    }

    public function compute(ReportFilters $filters): ReportResult
    {
        $runs = PayrollRun::query()
            ->when($filters->period !== null, fn ($q) => $q->where('period', $filters->period))
            ->orderByDesc('period')
            ->get();

        $lines = PayrollLine::query()
            ->with(['run', 'staffProfile.user', 'staffProfile.branch'])
            ->whereIn('payroll_run_id', $runs->pluck('id'))
            ->when(
                $filters->branchId !== null,
                fn ($q) => $q->whereHas('staffProfile', fn ($s) => $s->where('branch_id', $filters->branchId)),
            )
            ->get();

        $rows = $lines->map(fn (PayrollLine $line): array => [
            'period' => $line->run->period,
            'status' => $line->run->status->value,
            'staff' => $line->staffProfile->displayName(),
            'branch' => Cell::text($line->staffProfile->branch?->name),
            'base' => $line->base_salary,
            'commission' => $line->commission_amount,
            'allowances' => $line->allowances_total,
            'deductions' => $line->deductions_total,
            'net' => $line->net_salary,
        ])->all();

        $sum = fn (callable $get): Money => Money::sum($lines->map($get));

        $net = $sum(fn (PayrollLine $l): Money => $l->netSalary());

        return new ReportResult(
            columns: [
                ReportColumn::text('period', 'Period'),
                ReportColumn::text('status', 'Status'),
                ReportColumn::text('staff', 'Staff'),
                ReportColumn::text('branch', 'Branch'),
                ReportColumn::money('base', 'Base'),
                ReportColumn::money('commission', 'Commission'),
                ReportColumn::money('allowances', 'Allowances'),
                ReportColumn::money('deductions', 'Deductions'),
                ReportColumn::money('net', 'Net'),
            ],
            rows: $rows,
            totals: [
                'period' => sprintf('%d payslips', count($rows)),
                'base' => $sum(fn (PayrollLine $l): Money => $l->baseSalary())->toDecimalString(),
                'commission' => $sum(fn (PayrollLine $l): Money => $l->commissionAmount())->toDecimalString(),
                'allowances' => $sum(fn (PayrollLine $l): Money => $l->allowancesTotal())->toDecimalString(),
                'deductions' => $sum(fn (PayrollLine $l): Money => $l->deductionsTotal())->toDecimalString(),
                'net' => $net->toDecimalString(),
            ],
            summary: [
                ['label' => 'Runs', 'value' => (string) $runs->count()],
                ['label' => 'Payslips', 'value' => (string) count($rows)],
                ['label' => 'Net Payroll', 'value' => $net->toDecimalString()],
            ],
            emptyMessage: 'No payroll runs for these filters.',
            reconciliation: 'Base + commission + allowances − deductions = net, per line and in total. Only finalized and paid runs have posted to the ledger: a draft run appears here with no corresponding Salary Expense entry, by design (§11 — HR generates, Finance finalizes).',
        );
    }
}
