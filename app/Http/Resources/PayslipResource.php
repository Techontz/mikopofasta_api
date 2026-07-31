<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Allowance;
use App\Models\Deduction;
use App\Models\PayrollLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One employee's payslip — Bank → Payroll, and §17's "Staff Payslip".
 *
 * A payroll line with the person attached. `PayrollLineResource` is the same
 * row seen from the run's side, where the reader already knows who everyone is
 * because they are looking at a list of them; this is the row seen from the
 * employee's side, where the department, the branch and the bank account are
 * the point.
 *
 * The two are separate rather than one resource with conditional fields because
 * the Bank screen matches on `PayrollRowSchema` — employee, staffNo,
 * department, branch, phone, bankName, accountNumber — and folding those into
 * the run's line resource would put seven columns on every row of a payroll
 * table that has no use for them.
 *
 * @mixin PayrollLine
 */
final class PayslipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $staff = $this->staffProfile;
        $run = $this->run;

        return [
            'id' => (string) $this->id,
            'payrollRunId' => (string) $this->payroll_run_id,
            'period' => $run->period,

            // The employee, as the screen names them.
            'staffProfileId' => (string) $this->staff_profile_id,
            'employee' => $staff->displayName(),
            'staffNo' => $staff->employee_number,

            /*
             * "Department" is the role. The legacy screen's column says
             * Department and this system has no such entity — what it is
             * actually showing is what the person does, which is their role.
             * Labelled honestly rather than left blank or invented.
             */
            'department' => $staff->user?->role?->name,
            'branch' => $staff->branch?->name,
            'phone' => $staff->user?->phone,

            'bankName' => $staff->bankDetail?->bank_name,
            'accountNumber' => $staff->bankDetail?->account_number,
            'paymentMethod' => $staff->payment_method->value,

            'salary' => $this->base_salary,
            'commissionAmount' => $this->commission_amount,
            'allowancesTotal' => $this->allowances_total,
            'deductionsTotal' => $this->deductions_total,
            'grossPay' => $this->grossPay()->toDecimalString(),
            'netSalary' => $this->net_salary,

            /*
             * The run's status, not a status of its own. A payslip is paid when
             * its run is paid — there is no per-employee payment state, because
             * §11 pays a run as one act and inventing a row-level one would
             * imply the company can pay half a payroll.
             */
            'status' => $run->status->value,
            'paidOn' => $run->paid_at?->toDateString(),

            'journalEntryId' => $this->journal_entry_id === null ? null : (string) $this->journal_entry_id,

            'allowances' => $this->whenLoaded('allowances', fn (): array => $this->allowances
                ->map(fn (Allowance $a): array => [
                    'id' => (string) $a->id,
                    'label' => $a->type->value,
                    'amount' => $a->amount,
                ])->all()),

            'deductions' => $this->whenLoaded('deductions', fn (): array => $this->deductions
                ->map(fn (Deduction $d): array => [
                    'id' => (string) $d->id,
                    'label' => $d->type->value,
                    'amount' => $d->amount,
                    'referenceId' => $d->reference_id === null ? null : (string) $d->reference_id,
                ])->all()),
        ];
    }
}
