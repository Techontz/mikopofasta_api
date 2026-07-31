<?php

declare(strict_types=1);

namespace App\Http\Requests\Charges;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The filter set the three charge registers share.
 *
 * One request class for all three because they take the same filters — the
 * screens differ in what they list, not in how they are narrowed. Query
 * parameters stay snake_case, matching §1 and every other list endpoint.
 */
final class IndexChargeRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Customer name, customer number or loan number.
            'search' => ['nullable', 'string', 'max:120'],

            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],

            'from' => ['nullable', 'date'],
            // Ordered rather than merely valid: a range whose end precedes its
            // start returns nothing, which reads as "no data" instead of "the
            // filter is wrong".
            'to' => ['nullable', 'date', 'after_or_equal:from'],

            'sort' => ['nullable', Rule::in(['date', 'amount', 'customer'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],

            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'to.after_or_equal' => 'The end of the range cannot fall before its start.',
        ];
    }
}
