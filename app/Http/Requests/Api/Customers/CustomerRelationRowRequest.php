<?php

namespace App\Http\Requests\Api\Customers;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * POST /customers/{customer}/next-of-kin and /guarantors from the profile — the same row rules as registration.
 */
class CustomerRelationRowRequest extends CustomerScopedRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'min:9', 'max:20'],
            'relationship' => ['required', Rule::in(StoreCustomerRequest::RELATIONSHIPS)],
            'address' => ['nullable', 'string', 'max:255'],
        ];

        if ($this->routeIs('*.guarantors.*')) {
            $rules += [
                'nidaNumber' => ['nullable', 'string', 'max:30'],
                'occupation' => ['nullable', 'string', 'max:150'],
            ];
        }

        return $rules;
    }
}
