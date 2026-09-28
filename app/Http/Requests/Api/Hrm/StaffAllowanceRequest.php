<?php

namespace App\Http\Requests\Api\Hrm;

use App\Models\StaffAllowance;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Staff Allowance Form" (spec §24): the reason (overtime / leave / transport / other) and the payroll period ("YYYY-MM", default
 * the current month) the allowance belongs to.
 */
class StaffAllowanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'blanch_id' => ['required', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'empl_id' => ['required', Rule::exists('employees', 'id')->where('company_id', $companyId)->where('branch_id', $this->integer('blanch_id'))],
            'new_amount' => ['required', 'numeric', 'min:1'],
            'remaks_allow' => ['nullable', 'string', 'max:1000'],
            'reason' => ['nullable', Rule::in(StaffAllowance::REASONS)],
            'payroll_period' => ['nullable', 'date_format:Y-m'],
        ];
    }
}
