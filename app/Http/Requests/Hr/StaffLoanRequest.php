<?php

declare(strict_types=1);

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Raising a staff loan — §14. */
final class StaffLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'staffProfileId' => ['required', 'integer', Rule::exists('staff_profiles', 'id')],
            'amount' => ['required', 'numeric', 'gt:0', 'max:100000000'],

            /*
             * How many payslips the loan is recovered over.
             *
             * A staff loan has no category to derive terms from — §14 describes
             * the ledger movement and nothing about pricing — so the term is
             * agreed per loan. Capped at three years because a recovery running
             * longer than that outlives most of the employment it depends on,
             * and a typo of 240 would take two decades to clear.
             */
            'recoveryPeriods' => ['required', 'integer', 'min:1', 'max:36'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'recoveryPeriods.max' => 'A staff loan is recovered over at most 36 payslips.',
        ];
    }
}
