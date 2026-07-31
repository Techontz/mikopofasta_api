<?php

declare(strict_types=1);

namespace App\Http\Requests\Repayments;

use App\Domain\Repayments\Enums\PaymentChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /webhooks/payments` — mirrors the frontend's
 * InboundPaymentWebhookSchema and §15.3's documented payload.
 *
 * `amount` is a decimal string, like every other money field: a JSON number is
 * a double and has already lost precision by the time PHP sees it.
 */
final class InboundPaymentRequest extends FormRequest
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
            'reference' => ['required', 'string', 'max:30'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'channel' => ['required', 'string', Rule::in(PaymentChannel::values())],
            'transactionId' => ['required', 'string', 'max:80'],
        ];
    }

    /**
     * §15.3's example payload uses snake_case `transaction_id`; the frontend's
     * schema uses `transactionId`. Both are accepted so a provider integration
     * written against either spelling works.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('transactionId') && $this->has('transaction_id')) {
            $this->merge(['transactionId' => $this->input('transaction_id')]);
        }
    }
}
