<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BranchRequest extends FormRequest
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
            'blanch_name' => ['required', 'string', 'max:255'],
            'region_id' => ['required', 'exists:regions,id'],
            'blanch_no' => ['required', 'string', 'max:30'],
            'branch_type' => ['required', 'in:main,sub'],
            'zone_id' => ['nullable', Rule::exists('zones', 'id')->where('company_id', $this->user()->company_id)],
        ];
    }

    /**
     * @return array{name: string, region_id: int, phone: string, type: string, zone_id: int|null}
     */
    public function branchData(): array
    {
        return [
            'name' => $this->string('blanch_name')->toString(),
            'region_id' => $this->integer('region_id'),
            'phone' => $this->string('blanch_no')->toString(),
            'type' => $this->string('branch_type')->toString(),
            'zone_id' => $this->filled('zone_id') ? $this->integer('zone_id') : null,
        ];
    }
}
