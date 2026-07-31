<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use App\Domain\Loans\Enums\LoanStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query parameters for GET /loans. Status is multi-select, like the customer
 * list's faceted filters.
 */
final class IndexLoanRequest extends FormRequest
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
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'status' => ['sometimes', 'nullable', 'array'],
            'status.*' => ['string', Rule::in(LoanStatus::values())],
            'customer_id' => ['sometimes', 'nullable', 'integer'],
            'branch_id' => ['sometimes', 'nullable', 'integer'],
            'loan_product_id' => ['sometimes', 'nullable', 'integer'],
            'officer_id' => ['sometimes', 'nullable', 'integer'],

            // The two lifecycle groupings the frontend uses for its tabs.
            'stage' => ['sometimes', 'nullable', 'string', Rule::in(['origination', 'open_book'])],

            'include_deleted' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $value = $this->query('status');

        if (is_string($value) && $value !== '') {
            $this->merge(['status' => explode(',', $value)]);
        }
    }
}
