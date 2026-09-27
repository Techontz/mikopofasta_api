<?php

namespace App\Http\Requests\Api\Loans;

use App\Http\Requests\Loans\LoanApplicationRequest as LiveLoanApplicationRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Live "Loan Application Form" fields (category_id, group_id, how_loan, day, session, rate, fee_status, reason)
 * plus the customer on create and the instalment on edit (live edit_loan).
 */
class LoanApplicationRequest extends LiveLoanApplicationRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['day'] = ['nullable', 'string'];
        $rules['instalment'] = ['nullable', 'numeric', 'min:0'];

        if ($this->isMethod('post')) {
            $rules['customer_id'] = ['required', Rule::exists('customers', 'id')->where('company_id', $this->user()->company_id)];
            // Each entry is validated against the borrower by LoanGuarantors.
            $rules['guarantors'] = ['nullable', 'array', 'max:10'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'category_id' => 'loan category',
            'how_loan' => 'loan amount applied',
            'session' => 'number of repayments',
            'rate' => 'interest formula',
            'fee_status' => 'deducted fee',
        ];
    }

    /**
     * @return array{loan_category_id: int, group_id: int|null, amount_applied: float, sessions: int, formula: string, fee_deduct: bool, reason: string, instalment: float}
     */
    public function loanData(): array
    {
        return parent::loanData() + ['instalment' => (float) $this->input('instalment', 0)];
    }
}
