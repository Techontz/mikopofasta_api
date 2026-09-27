<?php

namespace App\Http\Requests\Api\Capital;

use App\Services\DividendService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Dividend payment (full or partial): the handwritten note gives the Dividend (Gawio) account two ways of withdrawing —
 * CASH (Company account) or BANK (a company bank account). The outstanding balance is checked again under a row lock
 * in {@see DividendService::pay()}.
 */
class DividendPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('capital.manage');
    }

    protected function failedAuthorization(): void
    {
        throw new HttpException(403, 'You do not have permission to perform this action.');
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        if (is_string($this->input('amount'))) {
            $clean['amount'] = str_replace([',', ' '], '', $this->input('amount'));
        }
        foreach (['idempotency_key', 'reference', 'bank_account_id'] as $field) {
            if ($this->input($field) === '') {
                $clean[$field] = null;
            }
        }
        $this->merge($clean);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999999'],
            'pay_method' => ['required', 'in:CASH,BANK'],
            'bank_account_id' => ['required_if:pay_method,BANK', 'nullable', 'integer', Rule::exists('bank_accounts', 'id')->where('company_id', $this->user()->company_id)],
            'reference' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.gt' => 'The amount to pay must be greater than zero.',
            'amount.decimal' => 'The amount to pay may have at most 2 decimal places.',
            'bank_account_id.required_if' => 'Select the company bank account to pay from.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['amount' => 'amount to pay', 'pay_method' => 'payment method', 'bank_account_id' => 'bank account'];
    }
}
