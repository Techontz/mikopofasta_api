<?php

declare(strict_types=1);

namespace App\Http\Requests\CustomerAdvances;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Which operational account the advance is paid out of.
 *
 * Both fields optional: omitted means the first company account that accepts
 * outflow, which is the same default a loan disbursement takes. `from_cash`
 * pays it over the counter out of the branch till instead.
 */
final class DisburseCustomerAdvanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'bank_account_id' => [
                'nullable', 'integer',
                Rule::exists('bank_accounts', 'id')->whereNull('deleted_at'),
            ],
            'from_cash' => ['nullable', 'boolean'],
        ];
    }
}
