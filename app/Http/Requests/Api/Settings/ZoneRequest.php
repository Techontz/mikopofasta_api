<?php

namespace App\Http\Requests\Api\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ZoneRequest extends FormRequest
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
            'zone_name' => [
                'required', 'string', 'max:255',
                Rule::unique('zones', 'name')->where('company_id', $this->user()->company_id)->ignore($this->route('zone')),
            ],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', Rule::exists('branches', 'id')->where('company_id', $this->user()->company_id)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['zone_name' => 'zone name', 'branch_ids.*' => 'branch'];
    }
}
