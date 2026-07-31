<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Support\Cell;
use App\Models\Allowance;
use App\Models\Deduction;
use App\Models\PayrollLine;
use App\Support\Money;

/**
 * `GET /reports/staff-payslip` — §17's "Staff Payslip".
 *
 * One row per employee per period, itemised: what each allowance and each
 * deduction actually was, rather than the two totals the Payroll report shows.
 *
 * That is the whole difference between the two, and why both exist. The Payroll
 * report answers "what did this run cost"; a payslip answers "why is my pay
 * this figure", which cannot be answered by a column called Deductions.
 *
 * Like the Payroll report it reads what the engine wrote rather than
 * recomputing. A payslip that disagreed with the one an employee was handed
 * would be worse than no payslip.
 */
final class StaffPayslipReport implements Report
{
    public function slug(): string
    {
        return 'staff-payslip';
    }

    public function title(): string
    {
        return 'Staff Payslip';
    }

    public function description(): string
    {
        return 'Each payslip with its allowances and deductions itemised.';
    }

    public function group(): string
    {
        return 'HR';
    }

    public function supportedFilters(): array
    {
        return ['branchId', 'period'];
    }

    public function compute(ReportFilters $filters): ReportResult
    {
        $lines = PayrollLine::query()
            ->with([
                'run', 'staffProfile.user', 'staffProfile.branch', 'staffProfile.bankDetail',
                'allowances', 'deductions',
            ])
            ->when(
                $filters->period !== null,
                fn ($q) => $q->whereHas('run', fn ($r) => $r->where('period', $filters->period)),
            )
            ->when(
                $filters->branchId !== null,
                fn ($q) => $q->whereHas('staffProfile', fn ($s) => $s->where('branch_id', $filters->branchId)),
            )
            ->get()
            ->sortBy([
                fn (PayrollLine $a, PayrollLine $b): int => $b->run->period <=> $a->run->period,
                fn (PayrollLine $a, PayrollLine $b): int => $a->staffProfile->displayName()
                    <=> $b->staffProfile->displayName(),
            ])
            ->values();

        $rows = $lines->map(fn (PayrollLine $line): array => [
            'period' => $line->run->period,
            'staff' => $line->staffProfile->displayName(),
            'employeeNumber' => $line->staffProfile->employee_number,
            'branch' => Cell::text($line->staffProfile->branch?->name),
            'base' => $line->base_salary,
            'commission' => $line->commission_amount,

            // Itemised, which is the point of this report as against Payroll.
            'allowanceDetail' => $this->itemise(
                $line->allowances->map(
                    static fn (Allowance $a): array => ['label' => $a->type->value, 'amount' => $a->amount],
                )->all(),
            ),
            'deductionDetail' => $this->itemise(
                $line->deductions->map(
                    static fn (Deduction $d): array => ['label' => $d->type->value, 'amount' => $d->amount],
                )->all(),
            ),

            'net' => $line->net_salary,
            'status' => $line->run->status->value,
        ])->all();

        $sum = fn (callable $get): Money => Money::sum($lines->map($get));

        return new ReportResult(
            columns: [
                ReportColumn::text('period', 'Period'),
                ReportColumn::text('staff', 'Staff'),
                ReportColumn::text('employeeNumber', 'Employee No.'),
                ReportColumn::text('branch', 'Branch'),
                ReportColumn::money('base', 'Base'),
                ReportColumn::money('commission', 'Commission'),
                ReportColumn::text('allowanceDetail', 'Allowances'),
                ReportColumn::text('deductionDetail', 'Deductions'),
                ReportColumn::money('net', 'Net'),
                ReportColumn::text('status', 'Run Status'),
            ],
            rows: $rows,
            totals: [
                'period' => sprintf('%d payslips', $lines->count()),
                'base' => $sum(fn (PayrollLine $l): Money => $l->baseSalary())->toDecimalString(),
                'commission' => $sum(fn (PayrollLine $l): Money => $l->commissionAmount())->toDecimalString(),
                'net' => $sum(fn (PayrollLine $l): Money => $l->netSalary())->toDecimalString(),
            ],
            summary: [
                ['label' => 'Payslips', 'value' => (string) $lines->count()],
                ['label' => 'Net Paid', 'value' => $sum(fn (PayrollLine $l): Money => $l->netSalary())->toDecimalString()],
            ],
            emptyMessage: 'No payslips for these filters.',
            reconciliation: 'Base + commission + allowances − deductions = net on every line. The itemised allowances and deductions sum to the totals the Payroll report shows, because both read the same rows the payroll engine wrote.',
        );
    }

    /**
     * "transport 50000.00 · airtime 20000.00", or an em dash when there is
     * nothing.
     *
     * A single text column rather than one column per type: the set of types
     * differs per employee, and a column per possible type would be mostly
     * empty and would change shape whenever a type is added.
     *
     * @param list<array{label: string, amount: string}> $items
     */
    private function itemise(array $items): string
    {
        if ($items === []) {
            return '—';
        }

        return implode(' · ', array_map(
            static fn (array $i): string => sprintf('%s %s', $i['label'], $i['amount']),
            $items,
        ));
    }
}
