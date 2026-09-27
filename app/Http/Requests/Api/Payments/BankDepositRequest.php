<?php

namespace App\Http\Requests\Api\Payments;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Teller bank deposit slip for cash receipts pending verification.
 */
class BankDepositRequest extends FormRequest
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
        return [
            'bank_account_id' => ['required', Rule::exists('bank_accounts', 'id')->where('company_id', $this->user()->company_id)],
            'slip_number' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:1'],
            'deposit_date' => ['required', 'date', 'before_or_equal:today'],
            'payment_ids' => ['required', 'array', 'min:1'],
            'payment_ids.*' => ['integer', 'distinct'],
        ];
    }
}
