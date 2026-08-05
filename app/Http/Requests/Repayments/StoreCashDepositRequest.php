<?php

declare(strict_types=1);

namespace App\Http\Requests\Repayments;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/cash-deposits` — a teller banking the day's takings.
 *
 * The payment ids are required and must be real. A deposit that named nothing
 * could never be reconciled, because reconciliation is precisely the act of
 * confirming that these payments are the ones the bank received.
 */
final class StoreCashDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => [
                'required', 'integer',
                Rule::exists('branches', 'id')->whereNull('deleted_at'),
            ],
            'bank_account_id' => [
                'required', 'integer',
                Rule::exists('bank_accounts', 'id')->whereNull('deleted_at'),
            ],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999.99'],

            'payment_ids' => ['required', 'array', 'min:1'],
            'payment_ids.*' => ['integer', Rule::exists('payments', 'id')],

            // The slip is evidence, not a formality — but a branch without a
            // scanner must still be able to bank its cash, so it is optional
            // here and its absence is visible on the reconciliation screen.
            'slip' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'payment_ids.required' => 'Select the cash payments this deposit covers.',
            'payment_ids.min' => 'Select at least one payment.',
            'amount.gt' => 'Enter an amount greater than zero.',
        ];
    }
}
