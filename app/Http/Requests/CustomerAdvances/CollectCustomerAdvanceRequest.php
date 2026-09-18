<?php

declare(strict_types=1);

namespace App\Http\Requests\CustomerAdvances;

use App\Domain\Repayments\Enums\PaymentChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A collection against a customer salary advance.
 *
 * `paid_at` is the business date of the collection and may be backdated, which
 * is what lets a payment taken on the last day of the month be recorded the
 * next morning and still belong to the month it was received in. It cannot be
 * in the future: money that has not arrived is not a payment.
 */
final class CollectCustomerAdvanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'channel' => ['required', Rule::in(array_map(
                static fn (PaymentChannel $c): string => $c->value,
                PaymentChannel::cases(),
            ))],
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['paid_at.before_or_equal' => 'A payment cannot be dated in the future.'];
    }
}
