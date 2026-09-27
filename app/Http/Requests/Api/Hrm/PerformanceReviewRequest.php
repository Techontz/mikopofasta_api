<?php

namespace App\Http\Requests\Api\Hrm;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * STAFF COMMISSION §15 "POST /staff/performance": targets, discipline, performance rating.
 */
class PerformanceReviewRequest extends FormRequest
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
            'period' => ['required', 'date_format:Y-m'],
            'targets' => ['nullable', 'string', 'max:2000'],
            'discipline' => ['nullable', Rule::in(['Excellent', 'Good', 'Fair', 'Poor'])],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
