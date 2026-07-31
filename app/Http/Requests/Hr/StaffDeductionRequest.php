<?php

declare(strict_types=1);

namespace App\Http\Requests\Hr;

use App\Domain\Hr\Enums\DeductionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Recording a penalty against somebody's pay — §11.
 *
 * `type` accepts only `penalty`. The staff fund contribution, loan recovery and
 * advance recovery are computed by payroll from a rate or a balance; a
 * hand-entered one would sit alongside the computed one and the employee would
 * be deducted twice for the same thing.
 */
final class StaffDeductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in([DeductionType::Penalty->value])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:100000000'],
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],

            /*
             * Required, and not short. This is money taken off somebody's
             * salary; "penalty" as a reason is not something anyone can defend
             * a year later, and the person recording it is the only one who
             * knows why.
             */
            'reason' => ['required', 'string', 'min:5', 'max:200'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'type.in' => 'Only a penalty can be recorded by hand — the rest are computed by payroll.',
            'period.regex' => 'Use a month in the form 2026-08.',
            'reason.min' => 'Say why this is being withheld.',
        ];
    }
}
