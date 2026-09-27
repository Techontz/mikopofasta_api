<?php

namespace App\Http\Requests\Api\Hrm;

use App\Models\Attendance;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Manual attendance record by HR (create or correct a day).
 */
class AttendanceRequest extends FormRequest
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
            'date' => ['required', 'date', 'before_or_equal:today'],
            'check_in' => ['nullable', 'required_if:status,present,late', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i', 'after:check_in'],
            'status' => ['required', Rule::in(array_keys(Attendance::STATUSES))],
            'remarks' => ['nullable', 'string', 'max:255'],
        ];
    }
}
