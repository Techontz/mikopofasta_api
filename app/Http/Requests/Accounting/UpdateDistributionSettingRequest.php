<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /distribution-setting — how distributable profit splits.
 *
 * ACCOUNT OVERVIEW §I.16 names both halves — "70% → Principal (Reinvestment)
 * 30% → Shareholders" — so both are supplied, and they must total 100.
 *
 * Requiring the pair rather than deriving one from the other is deliberate: an
 * administrator setting reinvestment to 60 and expecting dividend to follow
 * should be told what the other half became, not left to assume. The check
 * below makes the intent explicit instead of silently completing it.
 */
final class UpdateDistributionSettingRequest extends FormRequest
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
            'reinvestmentPercentage' => ['required', 'numeric', 'between:0,100'],
            'dividendPercentage' => ['required', 'numeric', 'between:0,100'],
        ];
    }

    /**
     * The two shares are an appropriation of one figure, so anything other
     * than 100 either leaves profit unappropriated or distributes money that
     * was never earned. Checked here rather than in the action: it is a
     * property of the input, and the officer should see it on the field.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $reinvestment = (float) $this->input('reinvestmentPercentage');
            $dividend = (float) $this->input('dividendPercentage');

            /* Compared on the hundredth, which is the precision the column
               stores; a float equality test would reject 33.33 + 66.67. */
            if (abs($reinvestment + $dividend - 100.0) > 0.001) {
                $validator->errors()->add(
                    'dividendPercentage',
                    'The reinvestment and dividend shares must add up to 100%.',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reinvestmentPercentage.required' => 'The reinvestment share is required.',
            'dividendPercentage.required' => 'The dividend share is required.',
        ];
    }
}
