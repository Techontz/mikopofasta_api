<?php

namespace App\Http\Requests\Api\Loans;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Live collateral fields (colateral_name / colateral_type / colateral_location / colateral_value + PDF attachment).
 */
class CollateralRequest extends FormRequest
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
            'colateral_name' => ['required', 'string', 'max:100'],
            'colateral_type' => ['required', 'string', 'max:100'],
            'colateral_location' => ['required', 'string', 'max:100'],
            'colateral_value' => ['required', 'numeric', 'min:0'],
            'attachment' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['attachment.mimes' => 'PDF file is Allowed please change Your file'];
    }
}
