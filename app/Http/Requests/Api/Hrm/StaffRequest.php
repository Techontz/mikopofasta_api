<?php

namespace App\Http\Requests\Api\Hrm;

use App\Enums\SalaryType;
use App\Models\Employee;
use App\Models\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * "Register Employee" modal (live fields) extended with the Documents' staff registration:
 * role, zone (zone managers), optional branch for HQ staff, login password, base salary,
 * commission eligibility and payment method.
 */
class StaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('salary')) {
            $this->merge(['salary' => str_replace(',', '', (string) $this->input('salary'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Employee|null $employee */
        $employee = $this->route('employee');
        $companyId = $this->user()->company_id;
        $creating = $employee === null;

        return [
            'empl_name' => ['required', 'string', 'max:100'],
            'emp_mname' => ['required', 'string', 'max:100'],
            'emp_lname' => ['required', 'string', 'max:100'],
            'empl_no' => ['required', 'string', 'max:20', Rule::unique('employees', 'phone')->ignore($employee?->id)],
            'date_birth' => ['required', 'date', 'before:today'],
            'empl_email' => ['required', 'email', 'max:150'],
            'blanch_id' => ['nullable', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'position_id' => ['required', Rule::in(array_keys(Employee::POSITIONS))],
            'username' => ['nullable', 'string', 'max:100'],
            'empl_sex' => ['nullable', Rule::in(['Male', 'Female', 'male', 'female'])],
            'role_id' => ['required', Rule::exists('roles', 'id')->where('company_id', $companyId)],
            'zone_id' => ['nullable', Rule::exists('zones', 'id')->where('company_id', $companyId)],
            'password' => [$creating ? 'nullable' : 'prohibited', 'string', 'min:6', 'max:100'],
            'salary' => ['nullable', 'numeric', 'min:0'],
            'salary_type' => ['nullable', Rule::enum(SalaryType::class)],
            'commission_eligible' => ['nullable', 'boolean'],
            'payment_method' => ['nullable', Rule::in(['bank', 'mobile'])],
            'account_name' => ['nullable', 'required_with:salary', 'string', 'max:100'],
            'account_number' => ['nullable', 'required_with:salary', 'string', 'max:50'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $role = $this->role();
                if ($role === null) {
                    return;
                }
                if ($role->scope === 'branch' && ! $this->filled('blanch_id')) {
                    $validator->errors()->add('blanch_id', 'Branch is required for branch staff');
                }
                if ($role->scope === 'zone' && ! $this->filled('zone_id')) {
                    $validator->errors()->add('zone_id', 'Zone is required for a zone manager');
                }
            },
        ];
    }

    public function role(): ?Role
    {
        return Role::where('company_id', $this->user()->company_id)->find($this->integer('role_id'));
    }

    /**
     * @return array<string, mixed>
     */
    public function employeeData(): array
    {
        $role = $this->role();

        return [
            'first_name' => $this->string('empl_name')->trim()->toString(),
            'middle_name' => $this->string('emp_mname')->trim()->toString(),
            'last_name' => $this->string('emp_lname')->trim()->toString(),
            'phone' => $this->string('empl_no')->trim()->toString(),
            'date_of_birth' => $this->date('date_birth'),
            'email' => $this->string('empl_email')->trim()->toString(),
            'branch_id' => $this->filled('blanch_id') ? $this->integer('blanch_id') : null,
            'position' => $this->string('position_id')->toString(),
            'username' => $this->filled('username') ? $this->string('username')->trim()->toString() : null,
            'gender' => $this->filled('empl_sex') ? strtolower($this->string('empl_sex')->toString()) : null,
            'role_id' => $role?->id,
            'zone_id' => $role?->scope === 'zone' && $this->filled('zone_id') ? $this->integer('zone_id') : null,
        ];
    }

    /**
     * Salary information supplied at registration, or null.
     *
     * @return array<string, mixed>|null
     */
    public function salaryData(): ?array
    {
        if (! $this->filled('salary')) {
            return null;
        }

        return [
            'salary' => (float) $this->input('salary'),
            'salary_type' => $this->input('salary_type') ?: SalaryType::forRole($this->role())->value,
            'commission_eligible' => $this->has('commission_eligible') ? $this->boolean('commission_eligible') : SalaryType::forRole($this->role()) !== SalaryType::Hq,
            'payment_method' => $this->input('payment_method') ?: 'bank',
            'account_name' => $this->string('account_name')->trim()->toString(),
            'account_number' => $this->string('account_number')->trim()->toString(),
            'fee' => 0,
        ];
    }
}
