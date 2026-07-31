<?php

declare(strict_types=1);

namespace App\Http\Requests\Repayments;

use App\Domain\Repayments\Enums\PaymentChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** `POST /payments/unmatched` — manually logging money nobody can place (§15.3). */
final class UnmatchedPaymentRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'channel' => ['required', 'string', Rule::in(PaymentChannel::values())],
            'transactionId' => ['sometimes', 'nullable', 'string', 'max:80'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'branchId' => ['sometimes', 'nullable', 'integer', Rule::exists('branches', 'id')],
        ];
    }
}
