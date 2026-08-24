<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Domain\Customers\Enums\CustomerApprovalStatus;
use App\Domain\Customers\Enums\CustomerStatus;
use App\Domain\Customers\Enums\KycStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query parameters for GET /customers.
 *
 * The three faceted filters mirror the frontend's customers-table exactly —
 * KYC, Approval and Status — and each accepts multiple values, because the
 * table's facets are multi-select (`arrIncludesSome`). `search` covers what
 * its search box covers: customer number, name and phone.
 *
 * Query keys are snake_case per spec §1.
 */
final class IndexCustomerRequest extends FormRequest
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

            'kyc_status' => ['sometimes', 'nullable', 'array'],
            'kyc_status.*' => ['string', Rule::in(KycStatus::values())],

            'status' => ['sometimes', 'nullable', 'array'],
            'status.*' => ['string', Rule::in(CustomerStatus::values())],

            'approval_status' => ['sometimes', 'nullable', 'array'],
            'approval_status.*' => ['string', Rule::in(CustomerApprovalStatus::values())],

            /*
             * `?loan_eligible=1` — the whole rule in one flag.
             *
             * The loan applicant selector used to assemble it from
             * `kyc_status=completed` + `approval_status=approved`, which is a
             * second copy of `Customer::isLoanEligible()` living in the
             * frontend and free to drift from it. It asks the API instead.
             */
            'loan_eligible' => ['sometimes', 'boolean'],

            'branch_id' => ['sometimes', 'nullable', 'integer'],
            'customer_category_id' => ['sometimes', 'nullable', 'integer'],

            'include_deleted' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Accepts `?status=active` as well as `?status[]=active`, so a caller
     * filtering on one value does not have to know the parameter is an array.
     */
    protected function prepareForValidation(): void
    {
        foreach (['kyc_status', 'status', 'approval_status'] as $key) {
            $value = $this->query($key);

            if (is_string($value) && $value !== '') {
                $this->merge([$key => explode(',', $value)]);
            }
        }
    }
}
