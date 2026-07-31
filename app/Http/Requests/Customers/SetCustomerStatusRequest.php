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
 */
final class SetCustomerStatusRequest extends FormRequest
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
            'active' => ['required', 'boolean'],
        ];
    }
}
