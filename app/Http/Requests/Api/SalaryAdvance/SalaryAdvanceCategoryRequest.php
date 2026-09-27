<?php

namespace App\Http\Requests\Api\SalaryAdvance;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Live admin/create_perfelal_setting and admin/modify_perfelal_setting fields.
 */
class SalaryAdvanceCategoryRequest extends FormRequest
{
    /**
     * Checked before validation so a user lacking the permission gets 403, not 422.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('settings.manage');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'perferal_name' => ['required', 'string', 'max:255'],
            'interest_name' => ['required', 'numeric', 'min:0', 'max:1000'],
            'from_amount' => ['required', 'numeric', 'min:0'],
            'to_amount' => ['required', 'numeric', 'gte:from_amount'],
            'fee_charger' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to_amount.gte' => 'To Amount must be greater than or equal to From Amount',
        ];
    }

    /**
     * @return array{name: string, interest_rate: float, amount_from: float, amount_to: float, fee: float}
     */
    public function categoryData(): array
    {
        return [
            'name' => $this->string('perferal_name')->toString(),
            'interest_rate' => $this->float('interest_name'),
            'amount_from' => $this->float('from_amount'),
            'amount_to' => $this->float('to_amount'),
            'fee' => $this->float('fee_charger'),
        ];
    }
}
