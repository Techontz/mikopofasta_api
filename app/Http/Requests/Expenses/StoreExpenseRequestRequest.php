<?php

declare(strict_types=1);

namespace App\Http\Requests\Expenses;

use App\Domain\Expenses\Enums\ExpenseScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors the frontend's `ExpenseClaimInputSchema` — expense, amount,
 * description, comment — plus the branch and date the screen supplies from
 * context.
 */
final class StoreExpenseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expenseCategoryId' => [
                'required', 'integer',
                // A retired category cannot be spent against — the whole point
                // of retiring it.
                Rule::exists('expense_categories', 'id')->whereNull('deleted_at'),
            ],

            /*
             * Optional, and only meaningful for a branch request. Omitted, it
             * falls back to the requester's own branch; sent on a headquarters
             * request it is ignored, because that register always books to head
             * office. Both rules live in RequestExpenseAction, so the same
             * answer is given whoever asks.
             */
            'branchId' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],

            /*
             * Which register the caller believes it is filing under.
             *
             * Optional, and never used to set anything — the category decides
             * that. It is a stated expectation, checked against the category
             * and refused if they disagree. The two screens each send their own
             * scope, so a branch screen can never quietly file a headquarters
             * cost because someone passed the wrong category id. The reporting
             * spec asks for mis-tagged expense detection; this prevents the
             * commonest way one gets created.
             */
            'scope' => ['nullable', Rule::enum(ExpenseScope::class)],

            /*
             * Where the money came from, when it was not the branch till.
             * Bank → Register Bank Expenses sends this; the branch and
             * headquarters expense screens do not, and their costs come out of
             * the till as they always have.
             */
            'bankAccountId' => ['nullable', 'integer', Rule::exists('bank_accounts', 'id')->whereNull('deleted_at')],

            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999.99'],
            'description' => ['required', 'string', 'min:2', 'max:255'],
            'comment' => ['nullable', 'string', 'max:300'],

            // A receipt is filed after the fact, so a past date is normal; a
            // future one is a typo, and booking a cost that has not happened
            // would misstate the month it lands in.
            'requestedOn' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'expenseCategoryId.required' => 'Choose an expense.',
            'expenseCategoryId.exists' => 'Choose an expense.',
            'amount.gt' => 'Enter an amount greater than zero.',
            'description.min' => 'Say what this is for.',
            'description.required' => 'Say what this is for.',
            'comment.max' => 'Keep the comment under 300 characters.',
            'requestedOn.before_or_equal' => 'An expense cannot be dated in the future.',
        ];
    }
}
