<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => strtoupper(fake()->company()),
            'registration_number' => fake()->numerify('####'),
            'address' => fake()->city(),
            'phone' => fake()->unique()->numerify('07########'),
            'email' => fake()->companyEmail(),
            'loan_fee_mode' => 'product',
            'penalty_type' => 'percentage',
            'penalty_value' => 20,
            'reserve_percent' => 20,
        ];
    }
}
