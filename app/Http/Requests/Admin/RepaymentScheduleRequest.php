<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Mirrors the frontend's schedule form. */
final class RepaymentScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:60'],
            // Letters, digits and underscores: the code is used as a lookup key
            // by seeders and product configuration, and a space in it makes
            // every one of those references fragile.
            'code' => ['required', 'string', 'min:2', 'max:20', 'regex:/^[A-Za-z0-9_]+$/'],
            /*
             * At least daily. Capped at a year: a schedule longer than that is
             * not a repayment frequency, and a typo of 3650 would generate a
             * single installment ten years out.
             */
            'frequencyDays' => ['required', 'integer', 'min:1', 'max:365'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.min' => 'Enter a schedule name.',
            'code.regex' => 'Use letters, digits and underscores only.',
            'frequencyDays.min' => 'A schedule repeats at least once a day.',
            'frequencyDays.max' => 'A repayment frequency longer than a year is not a schedule.',
        ];
    }
}
