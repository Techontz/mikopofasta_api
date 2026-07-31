<?php

declare(strict_types=1);

namespace App\Http\Requests\Treasury;

use App\Domain\Treasury\Enums\BankTransferKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors the frontend's `TransferInputSchema`, plus the kind and the charge.
 *
 * Which destination field is required depends on `kind`, and that rule lives in
 * RequestBankTransferAction — it describes the record rather than one payload,
 * and the seeder obeys it too.
 */
final class StoreBankTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(BankTransferKind::class)],
            'fromAccountId' => [
                'required', 'integer',
                Rule::exists('bank_accounts', 'id')->whereNull('deleted_at'),
            ],
            'toAccountId' => ['nullable', 'integer', Rule::exists('bank_accounts', 'id')->whereNull('deleted_at')],
            'toBranchId' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],

            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999.99'],
            // A bank may make no charge at all, so zero is valid; negative is
            // a refund, which is not what this field means.
            'chargeFee' => ['nullable', 'numeric', 'min:0', 'max:99999999999999.99'],

            'reason' => ['required', 'string', 'min:1', 'max:120'],
            'reference' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:500'],
            'transferredOn' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.gt' => 'Enter an amount greater than zero.',
            'reason.required' => 'Choose a reason.',
            'reference.max' => 'Keep the reference under 40 characters.',
            'description.max' => 'Keep the description under 500 characters.',
            'transferredOn.before_or_equal' => 'A transfer cannot be dated in the future.',
        ];
    }
}
