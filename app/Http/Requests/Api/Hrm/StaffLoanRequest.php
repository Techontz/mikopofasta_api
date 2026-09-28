<?php

namespace App\Http\Requests\Api\Hrm;

use App\Enums\Duration;
use App\Models\StaffLoanCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * "Apply Staff Loan" modal.
 */
class StaffLoanRequest extends FormRequest
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
        $companyId = $this->user()->company_id;

        return [
            'blanch_id' => ['required', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'empl_id' => ['required', Rule::exists('employees', 'id')->where('company_id', $companyId)->where('branch_id', $this->integer('blanch_id'))],
            'category_id' => ['required', Rule::exists('staff_loan_categories', 'id')->where('company_id', $companyId)],
            'loan_amount' => ['required', 'numeric', 'min:1'],
            'day' => ['required', Rule::enum(Duration::class)],
            'session' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $category = $this->category();
                if ($category === null) {
                    return;
                }

                $amount = (float) $this->input('loan_amount');
                if ($amount < (float) $category->amount_from || $amount > (float) $category->amount_to) {
                    $validator->errors()->add('loan_amount', 'Loan amount must be between '.money($category->amount_from).' and '.money($category->amount_to));
                }

                $sessions = $this->integer('session');
                if ($sessions < $category->repayment_from || $sessions > $category->repayment_to) {
                    $validator->errors()->add('session', "Number of repayments must be between {$category->repayment_from} and {$category->repayment_to}");
                }
            },
        ];
    }

    public function category(): ?StaffLoanCategory
    {
        return StaffLoanCategory::where('company_id', $this->user()->company_id)->find($this->integer('category_id'));
    }

    /**
     * @return array<string, mixed>
     */
    public function loanData(): array
    {
        return [
            'branch_id' => $this->integer('blanch_id'),
            'employee_id' => $this->integer('empl_id'),
            'staff_loan_category_id' => $this->integer('category_id'),
            'amount_applied' => (float) $this->input('loan_amount'),
            'duration' => $this->string('day')->toString(),
            'sessions' => $this->integer('session'),
            'reason' => $this->string('reason')->trim()->toString(),
        ];
    }
}
