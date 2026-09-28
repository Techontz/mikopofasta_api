<?php

namespace App\Http\Requests\Api\Hrm;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "Staff salary Advance Category" form and its edit modal.
 */
class StaffSalaryAdvanceCategoryRequest extends FormRequest
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
            'cate_name' => ['required', 'string', 'max:100'],
            'from_amount' => ['required', 'numeric', 'min:0'],
            'to_amount' => ['required', 'numeric', 'gte:from_amount'],
            'fee' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array{name: string, amount_from: float, amount_to: float, fee: float}
     */
    public function categoryData(): array
    {
        return [
            'name' => $this->string('cate_name')->trim()->toString(),
            'amount_from' => (float) $this->input('from_amount'),
            'amount_to' => (float) $this->input('to_amount'),
            'fee' => (float) $this->input('fee'),
        ];
    }
}
