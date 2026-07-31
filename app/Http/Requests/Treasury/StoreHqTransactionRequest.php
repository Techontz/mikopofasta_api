<?php

declare(strict_types=1);

namespace App\Http\Requests\Treasury;

use App\Domain\Treasury\Enums\HqTransactionDirection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Raising a headquarters movement.
 *
 * Which account columns are required depends on `direction`, and that rule is
 * enforced in RequestHqTransactionAction rather than here — it is a property of
 * the record, not of one HTTP payload, and the seeder has to obey it too. What
 * this class checks is that whatever was sent refers to real rows.
 */
final class StoreHqTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::enum(HqTransactionDirection::class)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999.99'],

            'fromAccountId' => ['nullable', 'integer', Rule::exists('hq_accounts', 'id')],
            'toAccountId' => ['nullable', 'integer', Rule::exists('hq_accounts', 'id')],

            'branchId' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'reason' => ['required', 'string', 'min:2', 'max:255'],

            // A movement is recorded after it is decided on, so a past date is
            // normal; a future one is a typo.
            'requestedOn' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.gt' => 'Enter an amount greater than zero.',
            'reason.required' => 'Say what this movement is for.',
            'reason.min' => 'Say what this movement is for.',
            'requestedOn.before_or_equal' => 'A transaction cannot be dated in the future.',
        ];
    }
}
