<?php

namespace App\Http\Requests\Api\Hrm;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Staff Deduction Form".
 */
class StaffDeductionRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'min:1'],
            'instalment' => ['required', 'integer', 'min:1', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
