<?php

namespace App\Http\Requests\Api\Capital;

use App\Enums\Account;
use App\Services\FloatService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Transfer Float Form": company money account → HQ PRINCIPAL A/C. The source is the COMPANY ACCOUNT, a company bank
 * account or the Investment RESERVE A/C ({@see FloatService::hqFloatSources()}) — never an asset, and never a branch.
 */
class CompanyFloatRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'min:1'],
            'from_account' => ['required', Rule::in(array_map(fn (Account $account): string => $account->value, FloatService::hqFloatSources()))],
            'bank_account_id' => [
                Rule::requiredIf(fn (): bool => $this->string('from_account')->toString() === Account::Bank->value),
                'nullable', 'integer',
                Rule::exists('bank_accounts', 'id')->where('company_id', $this->user()->company_id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['from_account' => 'source account', 'bank_account_id' => 'bank account'];
    }
}
