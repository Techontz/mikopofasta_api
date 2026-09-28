<?php

namespace App\Services\Hrm;

use App\Models\ApprovalPolicy;
use App\Models\CommissionAllocation;
use App\Models\Employee;
use App\Models\NegligenceDeduction;
use App\Models\NegligenceRecovery;
use App\Models\PayrollRun;
use App\Services\Approvals\SegregationOfDuties;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff negligence / loss deductions (spec §23, §57).
 *
 *  HR creates (pending) → FINANCE approves (approved) → the employee's next COMMISSION PAYMENT recovers it (recovering →
 *  recovered). Salary is never touched: the recovery is capped at the commission being paid, and whatever that commission
 *  could not cover stays outstanding and is carried forward automatically to the next commission.
 *
 * The money recovered goes to the PRINCIPAL A/C (operational capital), never to the Staff Fund: the commission payment journal
 * posts Dr COMMISSION PAYABLE / Cr WRITE-OFF EXPENSE (loss recovered — a contra-expense, never income, like the principal part of
 * a written-off loan recovery) and Dr PRINCIPAL A/C / Cr paying account ({@see CommissionPayments}). Payroll runs that still
 * carry commission (legacy, before the commission payment flow) recover it the same way through {@see PayrollEngine}.
 *
 * Segregation of duties: the HR employee who created the deduction, and the employee it is charged to, cannot approve it
 * (the Super Admin may — {@see SegregationOfDuties}).
 */
class NegligenceDeductions
{
    public function __construct(private readonly SegregationOfDuties $duties) {}

    public function create(Employee $employee, float $amount, string $reason, Employee $creator): NegligenceDeduction
    {
        return NegligenceDeduction::create([
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'branch_id' => $employee->branch_id,
            'amount' => round($amount, 2),
            'recovered_amount' => 0,
            'reason' => $reason,
            'status' => NegligenceDeduction::STATUS_PENDING,
            'created_by' => $creator->id,
        ]);
    }

    /**
     * Finance approval. Nothing is posted: the deduction waits for the employee's next commission.
     *
     * @throws ValidationException
     */
    public function approve(NegligenceDeduction $deduction, Employee $approver): void
    {
        $this->assertPending($deduction);
        $this->duties->assertCanApprove([$deduction->created_by, $deduction->employee_id], $approver, 'negligence deduction', workflow: ApprovalPolicy::PAYROLL);

        $deduction->update(['status' => NegligenceDeduction::STATUS_APPROVED, 'approved_by' => $approver->id, 'approved_at' => now()]);
    }

    /**
     * @throws ValidationException
     */
    public function reject(NegligenceDeduction $deduction, Employee $rejecter, string $reason): void
    {
        $this->assertPending($deduction);
        $this->duties->assertCanApprove([$deduction->created_by, $deduction->employee_id], $rejecter, 'negligence deduction', workflow: ApprovalPolicy::PAYROLL);

        $deduction->update(['status' => NegligenceDeduction::STATUS_REJECTED, 'rejected_by' => $rejecter->id, 'rejected_at' => now(), 'rejection_reason' => $reason]);
    }

    /**
     * Approved negligence of an employee still to be recovered from commission.
     */
    public function outstandingFor(int $employeeId): float
    {
        return round((float) NegligenceDeduction::recoverable()->where('employee_id', $employeeId)->get()->sum(fn (NegligenceDeduction $deduction): float => $deduction->outstandingAmount()), 2);
    }

    /**
     * Recover up to $amount (never more than the commission being paid) from the employee's approved deductions, oldest first,
     * and record one recovery row per deduction against the commission period and the commission payment (or, legacy, the
     * payroll run) it was taken from. The remainder of every deduction stays outstanding for the next commission.
     *
     * @return Collection<int, NegligenceRecovery>
     */
    public function recover(int $employeeId, float $amount, float $commission, CarbonInterface $period, ?PayrollRun $run = null, ?CommissionAllocation $allocation = null): Collection
    {
        $left = round(min($amount, $commission), 2);
        $recoveries = collect();

        if ($left <= 0) {
            return $recoveries;
        }

        return DB::transaction(function () use ($employeeId, $left, $commission, $period, $run, $allocation, $recoveries): Collection {
            $deductions = NegligenceDeduction::recoverable()->where('employee_id', $employeeId)->orderBy('id')->lockForUpdate()->get();

            foreach ($deductions as $deduction) {
                $portion = round(min($left, $deduction->outstandingAmount()), 2);
                if ($portion <= 0) {
                    continue;
                }
                $left = round($left - $portion, 2);
                $recovered = round((float) $deduction->recovered_amount + $portion, 2);
                $outstanding = round((float) $deduction->amount - $recovered, 2);

                $deduction->update([
                    'recovered_amount' => $recovered,
                    'status' => $outstanding <= 0 ? NegligenceDeduction::STATUS_RECOVERED : NegligenceDeduction::STATUS_RECOVERING,
                ]);

                $recoveries->push(NegligenceRecovery::create([
                    'negligence_deduction_id' => $deduction->id,
                    'payroll_run_id' => $run?->id,
                    'commission_allocation_id' => $allocation?->id,
                    'period' => $period->toDateString(),
                    'commission' => round($commission, 2),
                    'amount' => $portion,
                    'outstanding_after' => max(0, $outstanding),
                ]));
            }

            return $recoveries;
        });
    }

    /**
     * @throws ValidationException
     */
    private function assertPending(NegligenceDeduction $deduction): void
    {
        if ($deduction->status !== NegligenceDeduction::STATUS_PENDING) {
            throw ValidationException::withMessages(['status' => 'Negligence deduction is not pending']);
        }
    }
}
