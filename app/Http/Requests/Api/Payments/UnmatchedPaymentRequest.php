<?php

namespace App\Http\Requests\Api\Payments;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Unmatched bank / mobile money receipt recorded by Finance into suspense (POST /payments/unmatched).
 */
class UnmatchedPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1'],
            ...ConfirmedPaymentRequest::channelRules($this),
            'phone' => ['nullable', 'string', 'max:20'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'branch_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
