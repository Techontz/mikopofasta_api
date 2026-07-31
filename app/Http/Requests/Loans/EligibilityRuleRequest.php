<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The category → product eligibility pivot (§2.3).
 *
 * Backs the eligibility editor the frontend's route map lists at
 * /admin/customer-categories/[id]/eligibility but has not built (readiness
 * report gap 4) — the rules were seed-only until now.
 */
final class EligibilityRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rules' => ['present', 'array'],
            'rules.*.loanProductId' => ['required', 'integer', Rule::exists('loan_products', 'id')->whereNull('deleted_at')],
            'rules.*.maxAmountOverride' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'gt:0'],
            'rules.*.requiresExtraApproval' => ['sometimes', 'boolean'],
        ];
    }
}
