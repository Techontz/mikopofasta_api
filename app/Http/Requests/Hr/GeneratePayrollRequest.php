<?php

declare(strict_types=1);

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /payroll/generate` — mirrors the frontend's GeneratePayrollInputSchema,
 * whose period is `/^\d{4}-\d{2}$/`.
 */
final class GeneratePayrollRequest extends FormRequest
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
            'period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['period.regex' => 'Period must be in YYYY-MM format.'];
    }
}
