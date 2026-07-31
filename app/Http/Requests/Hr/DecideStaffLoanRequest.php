<?php

declare(strict_types=1);

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Approving, rejecting or disbursing a staff loan — §16.7–16.8. */
final class DecideStaffLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'loanId' => ['required', 'integer', Rule::exists('staff_loans', 'id')],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
