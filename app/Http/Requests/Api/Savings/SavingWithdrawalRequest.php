<?php

namespace App\Http\Requests\Api\Savings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Live admin/withdrawal_saving fields: amount, "withdrawal by" (TAKEN / CLEAR LOAN) and account (CLEAR only).
 */
class SavingWithdrawalRequest extends FormRequest
{
    /**
     * Checked before validation so a user lacking the permission gets 403, not 422.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('savings.manage');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'with_sav' => ['required', 'numeric', 'min:1'],
            'action' => ['required', 'in:TAKEN,CLEAR'],
            'method' => ['required_if:action,CLEAR', 'nullable', 'in:CASH'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'with_sav.required' => 'Please enter withdrawal amount',
            'action.required' => 'Please select withdrawal by',
            'method.required_if' => 'Please select account',
        ];
    }
}
