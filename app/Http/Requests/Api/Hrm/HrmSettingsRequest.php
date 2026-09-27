<?php

namespace App\Http\Requests\Api\Hrm;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Commission pool %, zone manager override %, staff and company staff fund contribution % and work start time.
 */
class HrmSettingsRequest extends FormRequest
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
            'commission_pool_percent' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'zone_override_percent' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'staff_fund_percent' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'work_start_time' => ['sometimes', 'required', 'date_format:H:i'],
        ];
    }
}
