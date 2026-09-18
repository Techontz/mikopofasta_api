<?php

declare(strict_types=1);

namespace App\Http\Requests\CustomerAdvances;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new customer salary advance request.
 *
 * The category is deliberately NOT accepted: the band is found from the amount,
 * so nobody can choose their own interest rate. `category_id` would be the one
 * field on this form that changes the price.
 */
final class StoreCustomerAdvanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'customer_id' => [
                'required', 'integer',
                Rule::exists('customers', 'id')->whereNull('deleted_at'),
            ],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
        ];
    }
}
