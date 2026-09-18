<?php

declare(strict_types=1);

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /payroll-settings — the Staff Fund and commission rates.
 *
 * All four are supplied together: the screen shows them as one form, and a
 * partial update would leave the audit entry unable to say what the whole
 * policy was after the change.
 */
final class UpdatePayrollSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /* Three decimals is what the columns store. */
        $rate = ['required', 'numeric', 'between:0,100', 'decimal:0,3'];

        return [
            'staffFundPercentage' => $rate,
            'commissionPoolPercentage' => $rate,
            'hqHoldPercentage' => $rate,
            'zoneOverridePercentage' => $rate,
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            '*.between' => 'A rate must be between 0% and 100%.',
            '*.decimal' => 'Use at most three decimal places.',
        ];
    }
}
