<?php

namespace App\Http\Requests\Api\Payments;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Teller "Deposit" modal (live admin/deposit_loan): depost, p_method (CASH, BANK or MNO), provider, recept. Every method follows the
 * cash process: held PENDING_VERIFICATION, banked on a deposit slip, posted when Finance verifies the slip. BANK and MNO name a bank
 * or network from the company's own list (Settings → Payment Channels).
 */
class TellerDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['depost' => preg_replace('/[^\d.]/', '', (string) $this->input('depost')), 'p_method' => strtoupper((string) $this->input('p_method'))]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'depost' => ['required', 'numeric', 'min:1'],
            'p_method' => ['required', 'string', 'in:CASH,BANK,MNO'],
            'provider' => ['exclude_if:p_method,CASH', 'required', 'string', 'max:100', Rule::exists('payment_providers', 'name')->where('company_id', $this->user()->company_id)->where('channel', (string) $this->input('p_method'))->where('is_active', true)],
            'recept' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['depost.required' => 'Enter Deposit Amount'];
    }
}
