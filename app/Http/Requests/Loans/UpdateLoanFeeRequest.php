<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use App\Domain\Loans\Enums\ChargeValueType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors the frontend's LoanFeeInputSchema.
 */
final class UpdateLoanFeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'feeType' => ['required', Rule::enum(ChargeValueType::class)],
            /*
             * A percentage cannot exceed 100; an amount can be any positive
             * figure. The ceiling therefore depends on the sibling field, which
             * is why this is not a flat `max`.
             */
            'feeAmount' => [
                'required', 'numeric', 'min:0',
                Rule::when(
                    $this->input('feeType') === ChargeValueType::PercentageValue->value,
                    ['max:100'],
                    ['max:99999999999999.99'],
                ),
            ],
            'insuranceAmount' => ['nullable', 'numeric', 'min:0', 'max:99999999999999.99'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'feeAmount.max' => $this->input('feeType') === ChargeValueType::PercentageValue->value
                ? 'A percentage fee cannot exceed 100.'
                : 'The fee amount is too large.',
        ];
    }
}
