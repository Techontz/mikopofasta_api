<?php

namespace App\Http\Requests\Api\Goals;

use App\Models\Goal;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Goals → Set Goal (company, zone, branch or officer target over a period).
 */
class GoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('goals.manage');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'title' => ['required', 'string', 'max:255'],
            'scope_type' => ['required', Rule::in(array_keys(Goal::SCOPES))],
            'branch_id' => ['nullable', 'required_if:scope_type,branch', 'integer', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'zone_id' => ['nullable', 'required_if:scope_type,zone', 'integer', Rule::exists('zones', 'id')->where('company_id', $companyId)],
            'employee_id' => ['nullable', 'required_if:scope_type,employee', 'integer', Rule::exists('employees', 'id')->where('company_id', $companyId)],
            'metric' => ['required', Rule::in(array_keys(Goal::METRICS))],
            'target' => ['required', 'numeric', 'min:1'],
            'period_type' => ['required', Rule::in(array_keys(Goal::PERIODS))],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function goalData(): array
    {
        $scope = $this->string('scope_type')->toString();

        return [
            'title' => $this->string('title')->toString(),
            'scope_type' => $scope,
            'branch_id' => $scope === 'branch' ? $this->integer('branch_id') : null,
            'zone_id' => $scope === 'zone' ? $this->integer('zone_id') : null,
            'employee_id' => $scope === 'employee' ? $this->integer('employee_id') : null,
            'metric' => $this->string('metric')->toString(),
            'target' => (float) $this->input('target'),
            'period_type' => $this->string('period_type')->toString(),
            'start_date' => $this->string('start_date')->toString(),
            'end_date' => $this->string('end_date')->toString(),
            'notes' => $this->input('notes'),
        ];
    }
}
