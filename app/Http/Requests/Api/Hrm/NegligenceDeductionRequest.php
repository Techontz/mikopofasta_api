<?php

namespace App\Http\Requests\Api\Hrm;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * HR "Negligence / Loss Deduction" form (spec §23): the staff member, the loss amount and the reason.
 */
class NegligenceDeductionRequest extends FormRequest
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
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('company_id', $this->user()->company_id)],
            'amount' => ['required', 'numeric', 'min:1'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
