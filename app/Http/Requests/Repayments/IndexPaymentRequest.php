<?php

declare(strict_types=1);

namespace App\Http\Requests\Repayments;

use App\Domain\Repayments\Enums\PaymentChannel;
use App\Domain\Repayments\Enums\PaymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Query parameters for GET /payments. */
final class IndexPaymentRequest extends FormRequest
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
            'status' => ['sometimes', 'nullable', 'array'],
            'status.*' => ['string', Rule::in(PaymentStatus::values())],
            'channel' => ['sometimes', 'nullable', 'array'],
            'channel.*' => ['string', Rule::in(PaymentChannel::values())],
            'loan_id' => ['sometimes', 'nullable', 'integer'],
            'branch_id' => ['sometimes', 'nullable', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['status', 'channel'] as $key) {
            $value = $this->query($key);

            if (is_string($value) && $value !== '') {
                $this->merge([$key => explode(',', $value)]);
            }
        }
    }
}
