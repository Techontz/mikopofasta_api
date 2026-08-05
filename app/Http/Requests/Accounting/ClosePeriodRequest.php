<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/accounting/periods/close` — Decision Register D1.
 *
 * The period is validated by shape here and by business rule in the action.
 * The regex refuses "2026-13" outright: Carbon would otherwise read it as
 * January 2027 and the close would recognise the wrong month's profit against
 * the right month's name.
 */
final class ClosePeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'period.regex' => 'Give the period as YYYY-MM, for example 2026-07.',
        ];
    }
}
