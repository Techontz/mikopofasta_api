<?php

namespace App\Http\Requests\Api\SalaryAdvance;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Live admin/create_perferal_loan fields.
 */
class SalaryAdvanceRequest extends FormRequest
{
    /**
     * Checked before validation so a user lacking the permission gets 403, not 422.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('salary_advance.manage');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'blanch_id' => ['required', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'customer_id' => ['required', Rule::exists('customers', 'id')->where('company_id', $companyId)->where('branch_id', $this->integer('blanch_id'))],
            'per_id' => ['required', Rule::exists('salary_advance_categories', 'id')->where('company_id', $companyId)],
            'loan_amount' => ['required', 'numeric', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'blanch_id.required' => 'Please select branch',
            'customer_id.required' => 'Please select customer',
            'customer_id.exists' => 'Customer does not belong to the selected branch',
            'per_id.required' => 'Please select category',
            'loan_amount.required' => 'Please enter loan amount',
        ];
    }
}
