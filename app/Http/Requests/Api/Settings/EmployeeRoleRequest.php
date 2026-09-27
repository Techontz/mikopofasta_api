<?php

namespace App\Http\Requests\Api\Settings;

use App\Models\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Assign a role (and, for zone-scoped roles, the zone) to an employee.
 */
class EmployeeRoleRequest extends FormRequest
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
        $companyId = $this->user()->company_id;

        return [
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('company_id', $companyId)],
            'zone_id' => [
                Rule::requiredIf(fn (): bool => $this->selectedRole()?->scope === 'zone'),
                'nullable', 'integer', Rule::exists('zones', 'id')->where('company_id', $companyId),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['zone_id.required' => 'Please select the zone this Zone Manager oversees.'];
    }

    public function selectedRole(): ?Role
    {
        return $this->filled('role_id')
            ? Role::where('company_id', $this->user()->company_id)->find($this->integer('role_id'))
            : null;
    }
}
