<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /customers/{customer}/status.
 *
 * Boolean rather than the status enum: `frozen` is unreachable here by design
 * (it needs a reason and a freeze record), so offering the full enum would
 * advertise a transition this endpoint refuses.
 *
 * A reason is required in BOTH directions. Suspending a customer stops them
 * borrowing, and lifting a suspension is the decision that lets them start
 * again — an auditor asks about the second one at least as often as the first.
 * This endpoint used to take nothing but the boolean, which meant an account
 * could be suspended with no recorded grounds at all.
 */
final class SetCustomerStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'A reason is required to change a customer’s status.',
            'reason.min' => 'Give a reason somebody reading this in a year could act on.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'active' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            /* Optional context — a case number, what the customer was told. */
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
