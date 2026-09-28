<?php

namespace App\Http\Requests\Api\Payments;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Finance matches a teller deposit slip with the bank statement line.
 */
class VerifyDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'statement_amount' => ['required', 'numeric', 'min:0'],
            'statement_reference' => ['required', 'string', 'max:100'],
        ];
    }
}
