<?php

declare(strict_types=1);

namespace App\Http\Requests\Treasury;

use App\Domain\Treasury\Enums\FloatTransferKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors the frontend's FloatTransferInputSchema.
 *
 * Which ids are required depends on `kind`, because the three float screens ask
 * for different things: the company→branch form names only a destination, the
 * branch→branch form names both branches, and the account→account form names
 * two accounts.
 */
final class StoreFloatTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $kind = $this->input('kind');
        $branch = Rule::exists('branches', 'id')->whereNull('deleted_at');
        $account = Rule::exists('chart_of_accounts', 'id')->whereNull('deleted_at');

        return [
            'kind' => ['required', Rule::enum(FloatTransferKind::class)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999.99'],

            // Source branch: only branch→branch names one; company float always
            // leaves head office, which the action resolves.
            'fromBranchId' => [
                Rule::requiredIf($kind === FloatTransferKind::BranchToBranch->value),
                'nullable', 'integer', $branch,
            ],
            'toBranchId' => [
                Rule::requiredIf(in_array($kind, [
                    FloatTransferKind::CompanyToBranch->value,
                    FloatTransferKind::BranchToBranch->value,
                    // The account→account form also picks a branch, to scope
                    // which accounts are offered.
                    FloatTransferKind::AccountToAccount->value,
                ], true)),
                'nullable', 'integer', $branch,
                // Moving money to the branch it came from is a no-op that would
                // still post two lines.
                'different:fromBranchId',
            ],

            'fromAccountId' => [
                Rule::requiredIf($kind === FloatTransferKind::AccountToAccount->value),
                'nullable', 'integer', $account,
            ],
            'toAccountId' => [
                Rule::requiredIf($kind === FloatTransferKind::AccountToAccount->value),
                'nullable', 'integer', $account, 'different:fromAccountId',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.gt' => 'Enter an amount greater than zero.',
            'toBranchId.different' => 'Choose two different branches.',
            'toAccountId.different' => 'Choose two different accounts.',
            'toBranchId.required' => 'Select a destination branch.',
            'fromBranchId.required' => 'Select a source branch.',
        ];
    }
}
