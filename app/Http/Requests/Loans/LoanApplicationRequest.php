<?php

namespace App\Http\Requests\Loans;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Loan Application Form" (live: create_loanapplication / modify_loanapplication).
 */
class LoanApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['how_loan' => preg_replace('/[^\d.]/', '', (string) $this->input('how_loan'))]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'category_id' => ['required', Rule::exists('loan_categories', 'id')->where('company_id', $companyId)],
            'group_id' => ['nullable', Rule::exists('groups', 'id')->where('company_id', $companyId)],
            'how_loan' => ['required', 'numeric', 'min:1'],
            'day' => ['required', 'string'],
            'session' => ['required', 'integer', 'min:1'],
            'rate' => ['required', Rule::in(['SIMPLE', 'FLATRATE', 'REDUCING'])],
            'fee_status' => ['required', Rule::in(['YES', 'NO'])],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array{loan_category_id: int, group_id: int|null, amount_applied: float, sessions: int, formula: string, fee_deduct: bool, reason: string}
     */
    public function loanData(): array
    {
        return [
            'loan_category_id' => $this->integer('category_id'),
            'group_id' => $this->filled('group_id') ? $this->integer('group_id') : null,
            'amount_applied' => (float) $this->input('how_loan'),
            'sessions' => $this->integer('session'),
            'formula' => $this->string('rate')->toString(),
            'fee_deduct' => $this->input('fee_status') === 'YES',
            'reason' => $this->string('reason')->trim()->toString(),
        ];
    }
}
