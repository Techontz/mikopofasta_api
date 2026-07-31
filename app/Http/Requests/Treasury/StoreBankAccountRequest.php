<?php

declare(strict_types=1);

namespace App\Http\Requests\Treasury;

use App\Domain\Treasury\Enums\Currency;
use App\Enums\ActiveStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/** Mirrors the frontend's `BankAccountInputSchema` — the Register Account form. */
class StoreBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'bankName' => ['required', 'string', 'min:2', 'max:100'],
            'accountName' => ['required', 'string', 'min:2', 'max:150'],
            'accountNumber' => [
                'required', 'string', 'min:6', 'max:50',
                // Digits and dashes only, matching the frontend's own rule.
                'regex:/^[0-9-]+$/',
                $this->accountNumberUniqueness(),
            ],
            'branchId' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'currency' => ['required', Rule::enum(Currency::class)],
            'openingBalance' => ['nullable', 'numeric', 'min:0', 'max:99999999999999.99'],
            'status' => ['required', Rule::enum(ActiveStatus::class)],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'bankName.min' => 'Enter the bank name.',
            'accountName.min' => 'Enter the account name.',
            'accountNumber.min' => 'An account number is at least 6 characters.',
            'accountNumber.regex' => 'Digits and dashes only.',
            'accountNumber.unique' => 'That account number is already registered.',
            'openingBalance.min' => 'An opening balance cannot be negative.',
            'description.max' => 'Keep the description under 500 characters.',
        ];
    }

    /**
     * Overridden on update to ignore the record being edited.
     *
     * Soft-deleted rows are excluded: a closed account should not stop the same
     * number being registered again, which is a real thing that happens when an
     * account is closed and reopened.
     */
    protected function accountNumberUniqueness(): Unique
    {
        return Rule::unique('bank_accounts', 'account_number')->whereNull('deleted_at');
    }
}
