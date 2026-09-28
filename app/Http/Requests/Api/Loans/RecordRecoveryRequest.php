<?php

namespace App\Http\Requests\Api\Loans;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Money recovered on a written-off loan (permission loans.recover). A Finance user records it confirmed in one step; anyone else's
 * entry waits for Finance. The server checks the unrecovered write-off balance per component; transaction_id is unique per channel
 * and required for a non-cash branch receipt.
 */
class RecordRecoveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('loans.recover');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999999'],
            'method' => ['required', Rule::in(['CASH', 'BANK', 'MOBILE', 'VODACOM', 'AIRTEL', 'TIGO', 'HALOPESA', 'MPESA'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'transaction_id' => ['nullable', 'string', 'max:100', Rule::unique('payments', 'transaction_id')->where('channel', strtoupper((string) $this->input('method')))],
            'bank_account_id' => ['nullable', 'integer', Rule::exists('bank_accounts', 'id')->where('company_id', $this->user()?->company_id)],
        ];
    }
}
