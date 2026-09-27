<?php

namespace App\Http\Resources\Api\V1\Hrm;

use App\Enums\SalaryType;
use App\Models\SalaryPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Salary slip / payslip.
 *
 * @mixin SalaryPayment
 */
class SalaryPaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => $this->employee?->full_name),
            'employee_number' => $this->whenLoaded('employee', fn () => $this->employee?->employee_number),
            'position' => $this->whenLoaded('employee', fn () => $this->employee?->role?->name ?? $this->employee?->position),
            'branch' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'period' => $this->whenLoaded('payrollRun', fn () => $this->payrollRun?->period->format('F Y')),
            'salary_type' => $this->salary_type,
            'salary_type_label' => SalaryType::tryFrom((string) $this->salary_type)?->label(),
            'salary' => (float) $this->salary,
            'commission' => (float) $this->commission,
            'allowance' => (float) $this->allowance,
            'gross' => round((float) $this->salary + (float) $this->commission + (float) $this->allowance, 2),
            'staff_fund' => (float) $this->staff_fund,
            'benefit_record' => (float) $this->staff_fund,
            'salary_advance' => (float) $this->salary_advance,
            'deduction' => (float) $this->deduction,
            'negligence' => (float) $this->negligence,
            'net_commission' => round((float) $this->commission - (float) $this->negligence, 2),
            'loan_restoration' => (float) $this->loan_restoration,
            'total_deductions' => round((float) $this->staff_fund + (float) $this->salary_advance + (float) $this->deduction + (float) $this->negligence + (float) $this->loan_restoration, 2),
            'take_home' => (float) $this->take_home,
            'phone' => $this->phone,
            'account_name' => $this->account_name,
            'account_number' => $this->account_number,
            'paid_from_account' => $this->paid_from_account,
            'paid_on' => $this->paid_on?->toDateString(),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
