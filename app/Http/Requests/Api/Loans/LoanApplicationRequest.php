<?php

namespace App\Http\Requests\Api\Loans;

use App\Enums\LoanMethod;
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
        // Enhancement §3.2: the first repayment date, today or later (the schedule is built from it at disbursement).
        $rules['start_date'] = ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'];
        // Normal, E-Mandate or Phone Bond, for any loan category; a new loan without it is Normal.
        $rules['loan_method'] = ['nullable', Rule::enum(LoanMethod::class)];
        // eSIMU: the enrolled phone securing the loan, and the Loan Officer's inspection when it secured an earlier loan.
        $rules['device_id'] = ['nullable', 'integer'];
        $rules['device_inspected'] = ['nullable', 'boolean'];

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
            'start_date' => 'start date',
            'loan_method' => 'loan method',
            'device_id' => 'eSIMU device',
        ];
    }

    /**
     * @return array{loan_category_id: int, group_id: int|null, amount_applied: float, sessions: int, formula: string, fee_deduct: bool, reason: string, instalment: float, start_date?: string|null, loan_method?: string, device_id?: int, device_inspected?: bool}
     */
    public function loanData(): array
    {
        return parent::loanData() + ['instalment' => (float) $this->input('instalment', 0)]
            + ($this->has('start_date') ? ['start_date' => $this->filled('start_date') ? $this->string('start_date')->toString() : null] : [])
            + ($this->filled('loan_method') ? ['loan_method' => $this->string('loan_method')->toString()] : [])
            + ($this->filled('device_id') ? ['device_id' => $this->integer('device_id'), 'device_inspected' => $this->boolean('device_inspected')] : []);
    }
}
