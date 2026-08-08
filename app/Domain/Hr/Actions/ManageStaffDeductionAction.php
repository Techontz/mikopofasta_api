<?php

declare(strict_types=1);

namespace App\Domain\Hr\Actions;

use App\Domain\Hr\DTOs\StaffDeductionData;
use App\Domain\Hr\Exceptions\StaffPayException;
use App\Enums\AuditAction;
use App\Models\PayrollRun;
use App\Models\StaffDeduction;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * A penalty withheld from somebody's pay — HRM → Staff → Deductions.
 *
 * ## Why only penalties
 *
 * §11 of the HR document lists four deduction types: *Staff Fund Contribution,
 * Loan Repayment, Advance Repayment, Penalties*. The first three are computed —
 * each follows from a rate or an outstanding balance, and payroll works it out.
 * A penalty cannot be, because it is somebody's decision about somebody else's
 * conduct.
 *
 * `DeductionType::Penalty` has been in the enum, mapped to an account by
 * `creditAccount()` and rendered by the frontend since the beginning, and no
 * code path could create one. This is that path.
 *
 * Recording a staff-fund, loan or advance deduction by hand is refused rather
 * than supported: it would sit alongside the computed one and the employee
 * would be deducted twice for the same thing.
 *
 * ## Cancelling
 *
 * Soft-deleted, and only while the period is still open. Once payroll has been
 * approved the penalty is part of an agreed payslip, and removing it would
 * change what somebody was paid after the fact — §16.1 again. A penalty applied
 * in error to a closed period is corrected by an allowance in the next one,
 * which leaves both the mistake and the correction on the record.
 */
final class ManageStaffDeductionAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function record(StaffProfile $staff, StaffDeductionData $data, User $actor): StaffDeduction
    {
        $this->guardPeriodOpen($data->period);

        return DB::transaction(function () use ($staff, $data, $actor): StaffDeduction {
            $deduction = StaffDeduction::query()->create([
                'staff_profile_id' => $staff->getKey(),
                'type' => $data->type,
                'amount' => $data->amount->toDecimalString(),
                'period' => $data->period,
                'reason' => $data->reason,
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->log(
                AuditAction::StaffDeductionRecorded,
                $deduction,
                after: $this->snapshot($deduction),
                actor: $actor,
            );

            return $deduction;
        });
    }

    public function cancel(StaffDeduction $deduction, User $actor): void
    {
        $this->guardPeriodOpen($deduction->period);

        DB::transaction(function () use ($deduction, $actor): void {
            $this->audit->log(
                AuditAction::StaffDeductionCancelled,
                $deduction,
                before: $this->snapshot($deduction),
                actor: $actor,
            );

            $deduction->delete();
        });
    }

    private function guardPeriodOpen(string $period): void
    {
        $run = PayrollRun::query()->where('period', $period)->first();

        if ($run !== null && ! $run->isEditable()) {
            throw StaffPayException::periodClosed($period, $run->status->label());
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(StaffDeduction $deduction): array
    {
        return $deduction->only(['staff_profile_id', 'type', 'amount', 'period', 'reason']);
    }
}
