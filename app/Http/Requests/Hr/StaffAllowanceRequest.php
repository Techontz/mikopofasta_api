<?php

declare(strict_types=1);

namespace App\Http\Requests\Hr;

use App\Domain\Hr\Enums\AllowanceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Granting or changing what an employee draws — §10. */
final class StaffAllowanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(AllowanceType::class)],

            /*
             * Above zero: a zero allowance is a row that changes nothing and
             * would sit on the payslip as a line item for nothing. Standing one
             * down is what the delete is for.
             */
            'amount' => ['required', 'numeric', 'gt:0', 'max:100000000'],

            // Absent means recurring. A bonus is forced to a period by the DTO
            // whatever arrives here — see StaffAllowanceData.
            'period' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],

            'reason' => ['nullable', 'string', 'max:200'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.gt' => 'An allowance of zero is not an allowance — stand it down instead.',
            'period.regex' => 'Use a month in the form 2026-08.',
        ];
    }
}
