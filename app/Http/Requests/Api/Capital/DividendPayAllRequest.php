<?php

namespace App\Http\Requests\Api\Capital;

use App\Services\DividendService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * PAY ALL OUTSTANDING of a declaration by CASH (Company account) or BANK. expected_total is the total the user
 * confirmed; {@see DividendService::payAll()} refuses the batch when the locked server total differs and always pays the
 * server-computed balances.
 */
class DividendPayAllRequest extends FormRequest
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
        if (is_string($this->input('expected_total'))) {
            $clean['expected_total'] = str_replace([',', ' '], '', $this->input('expected_total'));
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
            'expected_total' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999'],
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
            'expected_total.required' => 'Review the outstanding total before paying.',
            'bank_account_id.required_if' => 'Select the company bank account to pay from.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['expected_total' => 'outstanding total', 'pay_method' => 'payment method', 'bank_account_id' => 'bank account'];
    }
}
