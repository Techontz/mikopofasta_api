<?php

namespace App\Http\Requests\Api\Hrm;

use App\Enums\SalaryType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Add Salary Information" modal (live) with the Documents' salary structure fields.
 */
class StaffSalaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'salary' => str_replace(',', '', (string) $this->input('salary')),
            'fee_salary' => str_replace(',', '', (string) ($this->input('fee_salary') ?? '0')),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'salary' => ['required', 'numeric', 'min:0'],
            'account_name' => ['required', 'string', 'max:100'],
            'account_number' => ['required', 'string', 'max:50'],
            'fee_salary' => ['required', 'numeric', 'min:0'],
            'salary_type' => ['required', Rule::enum(SalaryType::class)],
            'commission_eligible' => ['required', 'boolean'],
            'payment_method' => ['required', Rule::in(['bank', 'mobile'])],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function salaryData(): array
    {
        $type = $this->string('salary_type')->toString();

        return [
            'salary' => (float) $this->input('salary'),
            'account_name' => $this->string('account_name')->trim()->toString(),
            'account_number' => $this->string('account_number')->trim()->toString(),
            'fee' => (float) $this->input('fee_salary'),
            'salary_type' => $type,
            'commission_eligible' => $type !== SalaryType::Hq->value && $this->boolean('commission_eligible'),
            'payment_method' => $this->string('payment_method')->toString(),
        ];
    }
}
