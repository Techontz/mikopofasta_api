<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors the frontend's LoanApplicationInputSchema (types/loan.ts).
 *
 * `principalAmount` is validated as a decimal STRING, not a number: a JSON
 * float has already lost precision by the time it reaches PHP, and the whole
 * money layer refuses to accept one. `decimal:0,2` also rejects a third
 * decimal place, which a TZS amount cannot have.
 */
final class ApplyForLoanRequest extends FormRequest
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
            'customerId' => ['required', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'loanProductId' => ['required', 'integer', Rule::exists('loan_products', 'id')->whereNull('deleted_at')],
            'repaymentScheduleId' => ['required', 'integer', Rule::exists('repayment_schedules', 'id')->whereNull('deleted_at')],
            'principalAmount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'tenureDays' => ['required', 'integer', 'min:1', 'max:3650'],
            'groupId' => ['sometimes', 'nullable', 'integer', Rule::exists('groups', 'id')->whereNull('deleted_at')],
        ];
    }
}
