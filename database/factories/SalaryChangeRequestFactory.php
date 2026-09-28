<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\SalaryChangeRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalaryChangeRequest>
 */
class SalaryChangeRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'company_id' => fn (array $attributes): int => Employee::find($attributes['employee_id'])->company_id,
            'current_values' => ['salary' => 500000.0],
            'proposed_values' => ['salary' => 600000.0, 'account_name' => fake()->name(), 'account_number' => fake()->numerify('##########'), 'fee' => 0.0, 'salary_type' => 'branch', 'commission_eligible' => true, 'payment_method' => 'bank'],
            'status' => SalaryChangeRequest::STATUS_SUBMITTED,
            'approval_stage' => SalaryChangeRequest::STAGE_FINANCE,
        ];
    }
}
