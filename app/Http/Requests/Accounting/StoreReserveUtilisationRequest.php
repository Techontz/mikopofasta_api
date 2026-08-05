<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Domain\Accounting\Enums\ReserveUtilisationPurpose;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/reserve/utilisations` — Decision Register D1.
 *
 * Which fields are required depends on the purpose, in the same way the float
 * transfer form varies by kind. Returning reserve to Capital has one possible
 * destination and needs none named; every other purpose spends real money into
 * a real account, so the requester must say which — the system has no basis for
 * guessing which till a new branch will draw on.
 */
final class StoreReserveUtilisationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $purpose = ReserveUtilisationPurpose::tryFrom((string) $this->input('purpose'));

        return [
            'purpose' => ['required', Rule::enum(ReserveUtilisationPurpose::class)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999.99'],

            // Long enough to be a reason. D1 requires every reserve movement to
            // be fully audited, and "transfer" is not an audit trail.
            'narrative' => ['required', 'string', 'min:10', 'max:500'],

            'target_branch_id' => [
                Rule::requiredIf($purpose?->requiresTargetBranch() ?? false),
                'nullable', 'integer',
                Rule::exists('branches', 'id')->whereNull('deleted_at'),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.gt' => 'Enter an amount greater than zero.',
            'narrative.min' => 'Explain what the reserve is being used for.',
            'target_branch_id.required' => 'Select the branch this reserve is for.',
        ];
    }
}
