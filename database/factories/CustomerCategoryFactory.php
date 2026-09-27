<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CustomerCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A customer type (loan categories reference it through loan_categories.customer_category_id).
 *
 * @extends Factory<CustomerCategory>
 */
class CustomerCategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = 'TYPE_'.Str::upper(Str::random(8));

        return [
            'company_id' => Company::factory(),
            'key' => Str::lower($code),
            'code' => $code,
            'name' => 'Customer Type '.Str::upper(Str::random(4)),
            'risk_level' => 'medium',
            'required_documents' => [],
            'form_schema' => [],
            'is_active' => true,
            'sort_order' => 0,
            'sector' => 'other',
        ];
    }
}
