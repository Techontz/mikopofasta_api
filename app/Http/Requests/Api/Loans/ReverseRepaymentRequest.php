<?php

namespace App\Http\Requests\Api\Loans;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Reason for reversing a loan repayment (permission loans.reverse_repayment).
 */
class ReverseRepaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('loans.reverse_repayment');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
