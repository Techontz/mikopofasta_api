<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/loans/{loan}/write-off`.
 *
 * The reason is mandatory and has a floor, because a write-off is the one
 * operation that reduces what a borrower owes with nobody paying. It is the
 * only account of why that decision was made, and it will be read by an auditor
 * long after everyone involved has forgotten.
 */
final class WriteOffLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.min' => 'Explain why this loan is being written off.',
        ];
    }
}
