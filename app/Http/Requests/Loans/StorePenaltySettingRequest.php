<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use App\Domain\Loans\Enums\ChargeValueType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors the frontend's PenaltySettingInputSchema.
 */
final class StorePenaltySettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'calculationType' => ['required', Rule::enum(ChargeValueType::class)],
            'amount' => [
                'required', 'numeric', 'min:0',
                Rule::when(
                    $this->input('calculationType') === ChargeValueType::PercentageValue->value,
                    ['max:100'],
                    ['max:99999999999999.999'],
                ),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.max' => $this->input('calculationType') === ChargeValueType::PercentageValue->value
                ? 'A percentage penalty cannot exceed 100.'
                : 'The penalty amount is too large.',
        ];
    }
}
