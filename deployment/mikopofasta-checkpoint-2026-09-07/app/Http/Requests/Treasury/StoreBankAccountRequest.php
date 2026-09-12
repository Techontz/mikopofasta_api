<?php

declare(strict_types=1);

namespace App\Http\Requests\Treasury;

use App\Domain\Treasury\Enums\AccountChannelType;
use App\Domain\Treasury\Enums\AccountUsage;
use App\Domain\Treasury\Enums\Currency;
use App\Enums\ActiveStatus;
use App\Models\BankAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Register Account — a company bank account or mobile money wallet.
 *
 * ## No branchId
 *
 * A company's financial channel is not a branch's property. The field is gone
 * from this form entirely rather than made optional; see BankAccountData.
 *
 * ## Uniqueness is checked against the derived key, not the typed number
 *
 * "0754 123 456" and "0754-123-456" are one wallet. The database enforces that
 * on `physical_account_key`, which it generates itself — but a constraint
 * violation surfaces as a 500, and an officer re-registering an account they
 * already have deserves a sentence rather than a stack trace. So the same rule
 * is asked here first, in the same terms, and the database remains the
 * authority if two requests race.
 */
class StoreBankAccountRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'accountType' => ['required', Rule::enum(AccountChannelType::class)],
            'usage' => ['required', Rule::enum(AccountUsage::class)],

            /*
             * Exactly one provider, decided by the channel. `required_if`
             * rather than `nullable` on both: an account that names no provider
             * cannot be reconciled against anything, and it collapses toward
             * the same uniqueness key as every other provider-less account.
             */
            'bankId' => [
                Rule::requiredIf(fn (): bool => $this->input('accountType') === AccountChannelType::Bank->value),
                'nullable', 'integer',
                Rule::exists('banks', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
            'mobileMoneyProviderId' => [
                Rule::requiredIf(fn (): bool => $this->input('accountType') === AccountChannelType::Mno->value),
                'nullable', 'integer',
                Rule::exists('mobile_money_providers', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],

            'accountName' => ['required', 'string', 'min:2', 'max:150'],
            'accountNumber' => [
                'required', 'string', 'min:6', 'max:50',
                /* Digits, spaces and dashes. A wallet is a phone number and a
                   bank number is often written in groups; both normalise to the
                   same key, so the formatting is the officer's business. */
                'regex:/^[0-9 +-]+$/',
            ],
            'currency' => ['required', Rule::enum(Currency::class)],
            'openingBalance' => ['nullable', 'numeric', 'min:0', 'max:99999999999999.99'],
            'status' => ['required', Rule::enum(ActiveStatus::class)],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->physicalAccountExists()) {
                    $validator->errors()->add(
                        'accountNumber',
                        'This account is already registered. The same number under the same provider cannot be '
                        .'registered twice, however it is formatted.',
                    );
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'bankId.required' => 'Choose the bank this account is held with.',
            'mobileMoneyProviderId.required' => 'Choose the mobile money provider.',
            'accountName.min' => 'Enter the account name.',
            'accountNumber.min' => 'An account number is at least 6 characters.',
            'accountNumber.regex' => 'Digits, spaces and dashes only.',
            'openingBalance.min' => 'An opening balance cannot be negative.',
            'description.max' => 'Keep the description under 500 characters.',
        ];
    }

    /**
     * Whether this physical account is already on the books.
     *
     * Compares on the normalised number — the same expression the database
     * generates `account_number_key` with — so a differently punctuated version
     * of an existing account is caught here rather than by a 1062.
     */
    protected function physicalAccountExists(): bool
    {
        $type = (string) $this->input('accountType');
        $key = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $this->input('accountNumber')));

        return BankAccount::query()
            ->where('account_type', $type)
            ->where('account_number_key', $key)
            ->when(
                $type === AccountChannelType::Bank->value,
                fn ($q) => $q->where('bank_id', $this->integer('bankId')),
                fn ($q) => $q->where('mobile_money_provider_id', $this->integer('mobileMoneyProviderId')),
            )
            ->when($this->ignoredAccountId() !== null, fn ($q) => $q->whereKeyNot($this->ignoredAccountId()))
            ->exists();
    }

    /** Overridden on update, so an account may keep its own number. */
    protected function ignoredAccountId(): ?int
    {
        return null;
    }
}
