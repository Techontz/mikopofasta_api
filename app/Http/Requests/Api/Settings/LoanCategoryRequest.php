<?php

namespace App\Http\Requests\Api\Settings;

use App\Http\Requests\Settings\LoanCategoryRequest as BaseLoanCategoryRequest;
use App\Models\InterestFormula;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Live loan category form plus the Documents additions: requires_mandate (E-MANDATE vs NORMAL flow) and Freeze Time (Days) -
 * the re-borrowing freeze. Each loan category belongs to exactly one customer type, sent as `customer_type_id` (the canonical
 * key; main_category_id, main_id, customer_category_id and customer_category_ids are refused).
 */
class LoanCategoryRequest extends BaseLoanCategoryRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'formular' => ['required', Rule::in(InterestFormula::where('is_enabled', true)->pluck('code')->all())],
            // No longer decides anything: the loan method is chosen on each loan application. Kept for older clients.
            'requires_mandate' => ['nullable', 'in:YES,NO'],
            'loan_method' => ['nullable', Rule::in(['normal', 'e_mandate', 'phone_bond'])],
            'freeze_time_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['customer_type_id' => 'customer type', 'freeze_time_days' => 'freeze time (days)'];
    }

    /**
     * Freeze Time (Days) is kept when omitted; a new category without it takes the company default (Settings → Penalty).
     *
     * @return array<string, mixed>
     */
    public function categoryData(): array
    {
        $method = $this->input('loan_method') ?: ($this->input('requires_mandate') === 'YES' ? 'e_mandate' : 'normal');

        return parent::categoryData()
            + ['requires_mandate' => $method === 'e_mandate', 'loan_method' => $method]
            + ($this->filled('freeze_time_days') ? ['freeze_time_days' => $this->integer('freeze_time_days')] : []);
    }
}
