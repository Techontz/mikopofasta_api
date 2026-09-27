<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $dateOfBirth = fake()->dateTimeBetween('-60 years', '-20 years');

        return [
            'branch_id' => Branch::factory(),
            'company_id' => fn (array $attributes): int => Branch::find($attributes['branch_id'])->company_id,
            'first_name' => strtoupper(fake()->firstName()),
            'middle_name' => strtoupper(fake()->firstName()),
            'last_name' => strtoupper(fake()->lastName()),
            'gender' => fake()->randomElement(['male', 'female']),
            'date_of_birth' => $dateOfBirth,
            'age' => now()->year - (int) $dateOfBirth->format('Y'),
            'phone' => fake()->numerify('2557########'),
            'work_status' => 'ser',
            'customer_type' => 'binafsi',
            'district' => fake()->city(),
            'ward' => fake()->streetName(),
            'street' => fake()->streetName(),
            'nickname' => fake()->firstName(),
            'marital_status' => 'Married',
            'account_type' => 'LOAN ACCOUNT',
            'business_type' => 'BIASHARA',
            'place_of_business' => fake()->city(),
            'dependents' => fake()->numberBetween(0, 6),
            'monthly_income' => fake()->numberBetween(100, 1500) * 1000,
            'id_number' => fake()->numerify('1990##########'),
            'customer_number' => fn (): string => sprintf('CU-%06d', fake()->unique()->numberBetween(1, 999999)),
            'kyc_status' => 'completed',
            'account_status' => 'active',
            'approval_status' => 'not_required',
            'status' => 'pending',
            'registration_step' => Customer::STEP_COMPLETE,
        ];
    }

    public function incomplete(int $step = Customer::STEP_ADDITIONAL): static
    {
        return $this->state(fn (): array => ['registration_step' => $step, 'kyc_status' => 'incomplete']);
    }
}
