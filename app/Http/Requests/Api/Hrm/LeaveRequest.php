<?php

namespace App\Http\Requests\Api\Hrm;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Employee Leave" modal.
 */
class LeaveRequest extends FormRequest
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
            'empl_id' => ['required', Rule::exists('employees', 'id')->where('company_id', $this->user()->company_id)],
            'stat_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:stat_date'],
            'remaks' => ['required', 'string', 'max:1000'],
        ];
    }
}
