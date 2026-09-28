<?php

namespace App\Http\Requests\Api\Bank;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bank → Company Cash ↔ Bank: move company money between the COMPANY ACCOUNT and a company bank account.
 * `direction` is company_to_bank or bank_to_company; `idempotency_key` (one per submission) prevents double posting.
 */
class CompanyFundTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('bank.manage');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::in(['company_to_bank', 'bank_to_company'])],
            'bank_account_id' => ['required', Rule::exists('bank_accounts', 'id')->where('company_id', $this->user()->company_id)],
            'amount' => ['required', 'numeric', 'min:1'],
            'reference' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['bank_account_id' => 'bank account'];
    }
}
