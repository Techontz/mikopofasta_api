<?php

declare(strict_types=1);

namespace App\Http\Requests\CustomerAdvances;

use App\Domain\CustomerAdvances\Enums\CustomerAdvanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The filter set the customer Salary Advance list screens share.
 *
 * `status` accepts the screens' vocabulary — `active` and `paid` — as well as
 * the backend's own, so an API caller using either is understood.
 */
final class IndexCustomerAdvanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in([...CustomerAdvanceStatus::values(), 'active', 'paid', 'repaid'])],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'category_id' => [
                'nullable', 'integer',
                Rule::exists('salary_advance_categories', 'id')->whereNull('deleted_at'),
            ],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['to.after_or_equal' => 'The end of the range cannot fall before its start.'];
    }
}
