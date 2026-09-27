<?php

namespace App\Http\Requests\Api\Hrm;

use App\Models\StaffSalaryAdvanceCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * "Request Salary Advance" modal (category select is named `fee` on the live form).
 */
class StaffSalaryAdvanceRequest extends FormRequest
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
            'fee' => ['required', Rule::exists('staff_salary_advance_categories', 'id')->where('company_id', $companyId)],
            'advance_amount' => ['required', 'numeric', 'min:1'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $category = $this->category();
                $amount = (float) $this->input('advance_amount');

                if ($category !== null && ($amount < (float) $category->amount_from || $amount > (float) $category->amount_to)) {
                    $validator->errors()->add('advance_amount', 'Amount must be between '.money($category->amount_from).' and '.money($category->amount_to));
                }
            },
        ];
    }

    public function category(): ?StaffSalaryAdvanceCategory
    {
        return StaffSalaryAdvanceCategory::where('company_id', $this->user()->company_id)->find($this->integer('fee'));
    }
}
