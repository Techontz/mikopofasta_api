<?php

declare(strict_types=1);

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mirrors the frontend's `SalaryAdvanceCategoryInputSchema`, plus the recovery
 * term.
 *
 * The band's internal coherence is checked here; whether it collides with
 * another band is checked in ManageSalaryAdvanceCategoryAction, because only
 * the server can see the neighbours.
 */
final class SalaryAdvanceCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'interestRate' => ['required', 'numeric', 'min:0', 'max:100'],
            'fromAmount' => ['required', 'numeric', 'min:0', 'max:99999999999999.99'],
            // Strictly above the floor: a band whose ceiling is below its floor
            // matches nothing and would silently never be offered.
            'toAmount' => ['required', 'numeric', 'gt:fromAmount', 'max:99999999999999.99'],
            'chargeFee' => ['required', 'numeric', 'min:0', 'max:99999999999999.99'],
            /*
             * At least one period — an advance recovered over zero payslips is
             * not recovered at all. Capped at 60 so a typo cannot commit an
             * employee to a five-year deduction.
             */
            'recoveryPeriods' => ['required', 'integer', 'min:1', 'max:60'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.min' => 'Enter a category name.',
            'interestRate.max' => 'Interest cannot exceed 100%.',
            'interestRate.min' => 'Interest cannot be negative.',
            'fromAmount.min' => 'Enter the lower bound.',
            'toAmount.gt' => 'The upper bound must be greater than the lower bound.',
            'chargeFee.min' => 'A charge fee cannot be negative.',
            'recoveryPeriods.min' => 'An advance must be recovered over at least one payroll period.',
        ];
    }
}
