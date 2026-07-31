<?php

declare(strict_types=1);

namespace App\Http\Requests\Treasury;

use App\Domain\Treasury\Enums\PayMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors the frontend's CapitalContributionInputSchema and the legacy form's
 * five fields.
 */
final class StoreCapitalContributionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'shareholderId' => ['required', 'integer', Rule::exists('shareholders', 'id')->whereNull('deleted_at')],
            // Capital of zero is not a contribution; the ledger rejects a
            // zero-amount line anyway, so it is refused here with a message.
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999.99'],
            'payMethod' => ['required', Rule::enum(PayMethod::class)],
            'receiptNo' => ['nullable', 'string', 'max:60'],
            // Required only when the money actually arrived as a cheque.
            'chequeNo' => [
                Rule::requiredIf(fn (): bool => $this->input('payMethod') === PayMethod::Cheque->value),
                'nullable', 'string', 'max:60',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.gt' => 'Enter an amount greater than zero.',
            'chequeNo.required' => 'A cheque number is required when the pay method is cheque.',
        ];
    }
}
