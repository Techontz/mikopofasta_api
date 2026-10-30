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
            // Enhancement §8.3: no slip number asked any more (an old client may still send one); an optional bank receipt.
            'slip_number' => ['nullable', 'string', 'max:100'],
            'receipt' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
            'amount' => ['required', 'numeric', 'min:1'],
            'deposit_date' => ['required', 'date', 'before_or_equal:today'],
            'payment_ids' => ['required', 'array', 'min:1'],
            'payment_ids.*' => ['integer', 'distinct'],
        ];
    }
}
