<?php

namespace App\Http\Requests\Api\Hrm;

use App\Enums\Duration;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Staff Loan Category" form and its edit modal.
 */
class StaffLoanCategoryRequest extends FormRequest
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
            'category_name' => ['required', 'string', 'max:100'],
            'from_amount' => ['required', 'numeric', 'min:0'],
            'to_amount' => ['required', 'numeric', 'gte:from_amount'],
            'interest' => ['required', 'numeric', 'min:0', 'max:1000'],
            'duration' => ['required', Rule::enum(Duration::class)],
            'from_repayment' => ['required', 'integer', 'min:1'],
            'to_repayment' => ['required', 'integer', 'gte:from_repayment'],
            'fee' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function categoryData(): array
    {
        return [
            'name' => $this->string('category_name')->trim()->toString(),
            'amount_from' => (float) $this->input('from_amount'),
            'amount_to' => (float) $this->input('to_amount'),
            'interest_rate' => (float) $this->input('interest'),
            'duration' => $this->string('duration')->toString(),
            'repayment_from' => $this->integer('from_repayment'),
            'repayment_to' => $this->integer('to_repayment'),
            'fee' => (float) $this->input('fee'),
        ];
    }
}
