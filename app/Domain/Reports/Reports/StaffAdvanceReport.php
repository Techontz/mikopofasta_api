<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Hr\Enums\StaffAdvanceStatus;
use App\Domain\Hr\Services\SalaryAdvanceCalculator;
use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Support\Cell;
use App\Models\StaffAdvance;
use App\Support\Money;

/**
 * `GET /reports/staff-advance` — §17's "Staff Advance Report".
 *
 * Every salary advance, its terms, and what is left to recover. Unlike a staff
 * loan an advance carries interest and a charge fee — priced from the band its
 * amount fell into — so the total repayable is more than the principal, and
 * both are shown rather than one being folded into the other.
 */
final class StaffAdvanceReport implements Report
{
    public function __construct(private readonly SalaryAdvanceCalculator $calculator) {}

    public function slug(): string
    {
        return 'staff-advance';
    }

    public function title(): string
    {
        return 'Staff Advance';
    }

    public function description(): string
    {
        return 'Salary advances, the terms each was priced on, and what is left to recover.';
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
        $advances = StaffAdvance::query()
            ->with(['staffProfile.user', 'staffProfile.branch', 'category'])
            ->when(
                $filters->branchId !== null,
                fn ($q) => $q->whereHas('staffProfile', fn ($s) => $s->where('branch_id', $filters->branchId)),
            )
            ->orderByDesc('id')
            ->get();

        $rows = $advances->map(fn (StaffAdvance $advance): array => [
            'reference' => $advance->reference,
            'staff' => $advance->staffProfile->displayName(),
            'branch' => Cell::text($advance->staffProfile->branch?->name),
            'category' => Cell::text($advance->category?->name),
            'status' => $advance->status->value,
            'principal' => $advance->amount,
            'interest' => $advance->interest_amount,
            'fee' => $advance->charge_fee,
            'repayable' => $this->calculator->totalRepayable($advance)->toDecimalString(),
            'recovered' => $advance->amount_recovered,
            'outstanding' => $this->calculator->outstanding($advance)->toDecimalString(),
        ])->all();

        $live = $advances->filter(
            fn (StaffAdvance $a): bool => $a->status === StaffAdvanceStatus::Disbursed,
        );

        $sum = fn (callable $get): Money => Money::sum($advances->map($get));
        $outstanding = Money::sum($live->map(fn (StaffAdvance $a): Money => $this->calculator->outstanding($a)));

        return new ReportResult(
            columns: [
                ReportColumn::text('reference', 'Reference'),
                ReportColumn::text('staff', 'Staff'),
                ReportColumn::text('branch', 'Branch'),
                ReportColumn::text('category', 'Category'),
                ReportColumn::text('status', 'Status'),
                ReportColumn::money('principal', 'Principal'),
                ReportColumn::money('interest', 'Interest'),
                ReportColumn::money('fee', 'Charge Fee'),
                ReportColumn::money('repayable', 'Repayable'),
                ReportColumn::money('recovered', 'Recovered'),
                ReportColumn::money('outstanding', 'Outstanding'),
            ],
            rows: $rows,
            totals: [
                'reference' => sprintf('%d advances', $advances->count()),
                'principal' => $sum(fn (StaffAdvance $a): Money => $a->amountMoney())->toDecimalString(),
                'interest' => $sum(fn (StaffAdvance $a): Money => $a->interestMoney())->toDecimalString(),
                'fee' => $sum(fn (StaffAdvance $a): Money => $a->chargeFeeMoney())->toDecimalString(),
                'repayable' => $sum(
                    fn (StaffAdvance $a): Money => $this->calculator->totalRepayable($a),
                )->toDecimalString(),
                'recovered' => $sum(fn (StaffAdvance $a): Money => $a->recoveredMoney())->toDecimalString(),
                'outstanding' => $outstanding->toDecimalString(),
            ],
            summary: [
                ['label' => 'Advances', 'value' => (string) $advances->count()],
                ['label' => 'Outstanding', 'value' => (string) $live->count()],
                ['label' => 'Left to recover', 'value' => $outstanding->toDecimalString()],
            ],
            emptyMessage: 'No salary advances for these filters.',
            reconciliation: 'Outstanding is the total repayable less what payroll has recovered. It does NOT equal 7020 Staff Advance Receivable, and should not: 7020 carries principal only, because a recovery credits the principal portion there and the interest and fee to 7000 Staff Fund — the fund that lent the money is the one that earns on it (§12).',
        );
    }
}
