<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Hr\Enums\DeductionType;
use App\Domain\Hr\Services\StaffFundReader;
use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Support\Cell;
use App\Models\Deduction;
use App\Models\StaffProfile;
use App\Support\Money;

/**
 * `GET /reports/staff-fund` — §17's "Staff Fund Balance".
 *
 * §12 makes the fund an internal revolving one: built from a percentage of
 * every salary, lent out as advances and loans, and repaid back into itself.
 * This is what each employee has put in, and what the fund holds against what
 * it has lent.
 *
 * Contributions are read from `deductions` — the rows a payroll run actually
 * wrote — rather than recomputed as ten per cent of everybody's salary. The
 * two should agree, and reading the rows is what makes the report capable of
 * showing that they do; recomputing would guarantee agreement by construction
 * and prove nothing.
 */
final class StaffFundReport implements Report
{
    public function __construct(private readonly StaffFundReader $fund) {}

    public function slug(): string
    {
        return 'staff-fund';
    }

    public function title(): string
    {
        return 'Staff Fund Balance';
    }

    public function description(): string
    {
        return 'What each employee has contributed to the Staff Fund, and what the fund has lent out.';
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
        $staff = StaffProfile::query()
            ->with(['user', 'branch'])
            ->when($filters->branchId !== null, fn ($q) => $q->where('branch_id', $filters->branchId))
            ->get();

        $contributions = $this->contributionsByStaff();

        $rows = $staff->map(function (StaffProfile $member) use ($contributions): array {
            $contributed = $contributions[(int) $member->getKey()] ?? Money::zero();

            return [
                'staff' => $member->displayName(),
                'employeeNumber' => $member->employee_number,
                'branch' => Cell::text($member->branch?->name),
                'baseSalary' => $member->base_salary,
                'contributed' => $contributed->toDecimalString(),
            ];
        })
            ->sortByDesc('contributed')
            ->values()
            ->all();

        $position = $this->fund->position();
        $totalContributed = Money::sum(array_values($contributions));

        return new ReportResult(
            columns: [
                ReportColumn::text('staff', 'Staff'),
                ReportColumn::text('employeeNumber', 'Employee No.'),
                ReportColumn::text('branch', 'Branch'),
                ReportColumn::money('baseSalary', 'Base Salary'),
                ReportColumn::money('contributed', 'Contributed'),
            ],
            rows: $rows,
            totals: [
                'staff' => sprintf('%d members', count($rows)),
                'contributed' => $totalContributed->toDecimalString(),
            ],
            summary: [
                ['label' => 'Fund Balance', 'value' => $position['balance']->toDecimalString()],
                ['label' => 'Contributed', 'value' => $totalContributed->toDecimalString()],
                ['label' => 'Lent Out', 'value' => $position['lentOut']->toDecimalString()],
                ['label' => 'Advances Outstanding', 'value' => $position['advancesOutstanding']->toDecimalString()],
                ['label' => 'Loans Outstanding', 'value' => $position['loansOutstanding']->toDecimalString()],
            ],
            emptyMessage: 'No staff, so no fund contributions.',
            reconciliation: 'Fund Balance is the ledger balance of 7000 Staff Fund. It exceeds member contributions by what the fund has earned on its own lending — §12: the fund "inazalisha faida ndani yake", so an advance\'s interest and charge fee return to it rather than to company income. Lent Out is what is still owed on advances and loans; the fund can never lend more than it holds.',
        );
    }

    /**
     * What each employee has had withheld for the fund, from the deduction
     * rows payroll wrote.
     *
     * @return array<int, Money>
     */
    private function contributionsByStaff(): array
    {
        /*
         * `toBase()` so the rows come back as plain objects. Left as an Eloquent
         * query the aliases would be read as model attributes, and `staff_id`
         * and `total` are not columns on `deductions` — the same aggregate trap
         * the charge register hit in Module 4.
         */
        $rows = Deduction::query()
            ->where('type', DeductionType::StaffFund)
            ->join('payroll_lines', 'payroll_lines.id', '=', 'deductions.payroll_line_id')
            ->toBase()
            ->selectRaw('payroll_lines.staff_profile_id AS staff_id, SUM(deductions.amount) AS total')
            ->groupBy('payroll_lines.staff_profile_id')
            ->get();

        $contributions = [];

        foreach ($rows as $row) {
            $contributions[(int) $row->staff_id] = Money::of((string) $row->total);
        }

        return $contributions;
    }
}
