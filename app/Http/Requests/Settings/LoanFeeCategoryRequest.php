<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class LoanFeeCategoryRequest extends FormRequest
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
            'loan_name' => ['required', 'string', 'max:255'],
            'loan_price' => ['required', 'numeric', 'min:0'],
            'loan_perday' => ['required', 'numeric', 'gte:loan_price'],
            'interest_formular' => ['required', 'numeric', 'min:0', 'max:1000'],
            'fee_category_type' => ['required', 'in:MONEY,PERCENTAGE'],
            'fee_value' => ['required', 'numeric', 'min:0'],
            // §47: insurance is no longer charged; the field is accepted from older clients and ignored.
            'insurance' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function categoryData(): array
    {
        return [
            'name' => $this->string('loan_name')->trim()->toString(),
            'amount_from' => $this->float('loan_price'),
            'amount_to' => $this->float('loan_perday'),
            'interest_rate' => $this->float('interest_formular'),
            'fee_type' => $this->input('fee_category_type') === 'PERCENTAGE' ? 'percentage' : 'money',
            'fee_value' => $this->float('fee_value'),
            'insurance' => 0,
        ];
    }
}
