<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Deduction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `SalaryAdvancePaymentSchema` in the frontend's
 * types/salary-advance.ts — one recovery instalment, as Salary Advance →
 * Salary Advance Repayment and the Paid List show it.
 *
 * The row is a payroll `deductions` row: an advance is repaid by being deducted
 * from a payslip, so the deduction *is* the payment. `date` is the payroll
 * period the deduction belongs to, which is the period the employee was paid
 * for — not when the run happened to be finalised.
 *
 * @mixin Deduction
 */
final class SalaryAdvancePaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $line = $this->payrollLine;
        $staff = $line?->staffProfile;

        /*
         * Keyed off ids rather than chained nullsafes. Every one of these
         * relations is genuinely optional at the type level but always present
         * on a committed row, and `?->x ?? ''` reads to static analysis as an
         * unreachable fallback. Testing the id says the same thing and is
         * honest about which column can actually be absent.
         */
        $period = $line !== null && $line->run !== null ? $line->run->period : '';

        return [
            'id' => (string) $this->id,
            'branch' => $staff !== null && $staff->branch_id !== null ? $staff->branch->name : '',
            // The employee. The schema calls it customerName because the legacy
            // screens reuse the customer table's markup for staff.
            'customerName' => $staff?->displayName() ?? '',
            'amount' => $this->amount,
            'date' => $period,

            'advanceId' => $this->reference_id === null ? null : (string) $this->reference_id,
            'staffProfileId' => $staff === null ? null : (string) $staff->getKey(),
            'payrollRunId' => $line === null ? null : (string) $line->payroll_run_id,
            'period' => $period,
        ];
    }
}
