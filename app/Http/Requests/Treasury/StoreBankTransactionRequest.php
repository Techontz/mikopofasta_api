<?php

declare(strict_types=1);

namespace App\Http\Requests\Treasury;

use App\Domain\Treasury\Enums\BankTransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Raising a movement on a bank account — Bank → Bank Transaction. */
final class StoreBankTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'bankAccountId' => [
                'required', 'integer',
                Rule::exists('bank_accounts', 'id')->whereNull('deleted_at'),
            ],
            'type' => ['required', Rule::enum(BankTransactionType::class)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999.99'],
            'branchId' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'note' => ['nullable', 'string', 'max:500'],
            'transactedOn' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.gt' => 'Enter an amount greater than zero.',
            'transactedOn.before_or_equal' => 'A transaction cannot be dated in the future.',
        ];
    }
}
