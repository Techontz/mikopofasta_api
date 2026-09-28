<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'company_id' => fn (array $attributes): int => Branch::find($attributes['branch_id'])->company_id,
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => fake()->unique()->numerify('07########'),
            'email' => fake()->safeEmail(),
            'username' => fake()->userName(),
            'gender' => fake()->randomElement(['male', 'female']),
            'position' => 'employee',
            'status' => 'active',
            'password' => 'password',
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (): array => ['position' => 'admin']);
    }
}
