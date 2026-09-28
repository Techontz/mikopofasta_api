<?php

namespace App\Http\Requests\Api\Capital;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Live "Add Capital" form (admin/create_capital), plus the uploaded receipt file ("Import Receipt").
 * `recept` is the typed receipt number; `receipt_file` is the scanned receipt (PDF or image).
 * The receiving company account is the COMPANY ACCOUNT for CASH and `bank_account_id` (required) for BANK;
 * `idempotency_key` (one per form submission) makes a retried request return the first contribution.
 */
class CapitalRequest extends FormRequest
{
    public const RECEIPT_MIMES = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    public const RECEIPT_MAX_KB = 5120;

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
            'share_id' => ['required', Rule::exists('share_holders', 'id')->where('company_id', $this->user()->company_id)],
            'amount' => ['required', 'numeric', 'min:1'],
            'pay_method' => ['required', 'in:CASH,BANK'],
            'bank_account_id' => ['nullable', 'required_if:pay_method,BANK', Rule::exists('bank_accounts', 'id')->where('company_id', $this->user()->company_id)],
            'contributed_at' => ['nullable', 'date', 'before_or_equal:now'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'recept' => ['nullable', 'string', 'max:50'],
            'chaque_no' => ['nullable', 'string', 'max:50'],
            'receipt_file' => ['nullable', 'file', 'mimes:'.implode(',', self::RECEIPT_MIMES), 'max:'.self::RECEIPT_MAX_KB],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['share_id' => 'shareholder', 'pay_method' => 'pay method', 'recept' => 'receipt', 'chaque_no' => 'cheque number', 'receipt_file' => 'import receipt', 'bank_account_id' => 'receiving bank account', 'contributed_at' => 'contribution date'];
    }
}
