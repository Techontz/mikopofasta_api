<?php

namespace Database\Factories;

use App\Enums\Duration;
use App\Enums\LoanStatus;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Loan>
 */
class LoanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'company_id' => fn (array $attributes): int => Customer::find($attributes['customer_id'])->company_id,
            'branch_id' => fn (array $attributes): int => Customer::find($attributes['customer_id'])->branch_id,
            'loan_category_id' => fn (array $attributes): int => LoanCategory::factory()->create(['company_id' => $attributes['company_id']])->id,
            'loan_number' => fake()->unique()->numerify('##############'),
            'amount_applied' => 100000,
            'amount_approved' => 0,
            'duration' => Duration::Weekly,
            'sessions' => 1,
            'formula' => 'SIMPLE',
            'fee_deduct' => true,
            'reason' => 'BIASHARA',
            'interest_rate' => 30,
            'interest_amount' => 30000,
            'total_payable' => 130000,
            'loan_fee' => 5000,
            'restoration' => 130000,
            'status' => LoanStatus::PendingManagerApproval,
        ];
    }
}
