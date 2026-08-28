<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Customers\Enums\CategorySector;
use App\Domain\Customers\Enums\RiskTier;
use App\Models\CustomerCategory;
use Illuminate\Database\Seeder;

/**
 * The customer categories — the KYC rule engine (§2.3).
 *
 * Mirrors the frontend's MOCK_CUSTOMER_CATEGORIES exactly: same names, codes,
 * risk tiers, required documents and dynamic form fields. Medium Entrepreneur
 * is the one category that requires extra approval, which is what exercises
 * the pending→approved path in the demo data.
 */
final class CustomerCategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->categories() as $definition) {
            CustomerCategory::query()->updateOrCreate(
                ['code' => $definition['code']],
                $definition,
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function categories(): array
    {
        return [
            [
                'name' => 'Boda Boda',
                'code' => 'BODA',
                'risk_tier' => RiskTier::High,
                'sector' => CategorySector::Business,
                'required_documents' => ['driving_license', 'motorcycle_registration'],
                'dynamic_form_schema' => [
                    ['key' => 'motorcycle_registration_number', 'label' => 'Motorcycle Registration Number', 'type' => 'text', 'required' => true],
                    ['key' => 'daily_income', 'label' => 'Average Daily Income (TZS)', 'type' => 'number', 'required' => true],
                    ['key' => 'route', 'label' => 'Usual Route/Stage', 'type' => 'text', 'required' => false],
                ],
                'requires_extra_approval' => false,
            ],
            [
                'name' => 'Small Entrepreneur',
                'code' => 'SME_SMALL',
                'risk_tier' => RiskTier::Medium,
                'sector' => CategorySector::Business,
                'required_documents' => ['business_license'],
                'dynamic_form_schema' => [
                    ['key' => 'business_type', 'label' => 'Business Type', 'type' => 'text', 'required' => true],
                    ['key' => 'business_location', 'label' => 'Business Location', 'type' => 'text', 'required' => true],
                    ['key' => 'daily_income', 'label' => 'Average Daily Income (TZS)', 'type' => 'number', 'required' => true],
                    ['key' => 'collateral', 'label' => 'Collateral Description', 'type' => 'textarea', 'required' => false],
                ],
                'requires_extra_approval' => false,
            ],
            [
                'name' => 'Medium Entrepreneur',
                'code' => 'SME_MEDIUM',
                'risk_tier' => RiskTier::Medium,
                'sector' => CategorySector::Business,
                'required_documents' => ['business_license', 'financial_statement'],
                'dynamic_form_schema' => [
                    ['key' => 'business_type', 'label' => 'Business Type', 'type' => 'text', 'required' => true],
                    ['key' => 'monthly_turnover', 'label' => 'Monthly Turnover (TZS)', 'type' => 'number', 'required' => true],
                    ['key' => 'years_in_business', 'label' => 'Years in Business', 'type' => 'number', 'required' => true],
                ],
                // The one category that demands a second pair of eyes (§2.3).
                'requires_extra_approval' => true,
            ],
            [
                'name' => 'Public Servant',
                'code' => 'PUBLIC_SERVANT',
                'risk_tier' => RiskTier::Low,
                'sector' => CategorySector::Employment,
                /* Sector, contract and salary are FIRST-CLASS columns on
                   `customers`, so they are declared as blocks this category
                   needs rather than repeated as schema fields. The schema
                   keeps only what is peculiar to a public servant. */
                'requires_sector' => true,
                'requires_contract' => true,
                'requires_salary' => true,
                'required_documents' => [
                    'confirmation_letter', 'salary_slip', 'bank_card', 'employee_id', 'national_id',
                ],
                'dynamic_form_schema' => [
                    ['key' => 'employer_name', 'label' => 'Employer Name', 'type' => 'text', 'required' => true],
                    ['key' => 'check_number', 'label' => 'Check Number', 'type' => 'text', 'required' => true],
                    ['key' => 'account_number', 'label' => 'Salary Account Number', 'type' => 'text', 'required' => true],
                ],
                'requires_extra_approval' => false,
            ],
            [
                'name' => 'Private Sector',
                'code' => 'PRIVATE_SECTOR',
                'risk_tier' => RiskTier::Low,
                'sector' => CategorySector::Employment,
                /* A company, not a ministry — see the 2026_08_31 migration. */
                'requires_sector' => false,
                'requires_employer' => true,
                'requires_contract' => true,
                'requires_salary' => true,
                'required_documents' => [
                    'employment_contract', 'bank_statement', 'national_id', 'driving_license',
                ],
                'dynamic_form_schema' => [
                    ['key' => 'employer_name', 'label' => 'Employer Name', 'type' => 'text', 'required' => true],
                    ['key' => 'employment_start_date', 'label' => 'Employment Start Date', 'type' => 'date', 'required' => true],
                    /* Free text, per the requirement — a job title is not a
                       list anybody can finish writing. */
                    ['key' => 'job_title', 'label' => 'Occupation / Job Title', 'type' => 'text', 'required' => true],
                ],
                'requires_extra_approval' => false,
            ],

            /*
             * The two the documents list and the original seed did not carry.
             * Neither has an employer, a contract or a payslip, so all three
             * blocks stay off and their questions live in the schema.
             */
            [
                'name' => 'Wanachuo',
                'code' => 'STUDENT',
                'risk_tier' => RiskTier::High,
                'sector' => CategorySector::Other,
                'requires_sector' => false,
                'requires_contract' => false,
                'requires_salary' => false,
                'required_documents' => ['student_id', 'national_id'],
                'dynamic_form_schema' => [
                    ['key' => 'institution_name', 'label' => 'Institution', 'type' => 'text', 'required' => true],
                    ['key' => 'course_of_study', 'label' => 'Course of Study', 'type' => 'text', 'required' => true],
                    ['key' => 'expected_completion_year', 'label' => 'Expected Completion Year', 'type' => 'number', 'required' => true],
                    ['key' => 'sponsor_name', 'label' => 'Sponsor or Guardian', 'type' => 'text', 'required' => false],
                ],
                'requires_extra_approval' => true,
            ],
            [
                'name' => 'Retired',
                'code' => 'RETIRED',
                'risk_tier' => RiskTier::Medium,
                'sector' => CategorySector::Other,
                'requires_sector' => false,
                'requires_contract' => false,
                'requires_salary' => false,
                'required_documents' => ['pension_statement', 'national_id'],
                'dynamic_form_schema' => [
                    ['key' => 'former_employer', 'label' => 'Former Employer', 'type' => 'text', 'required' => true],
                    ['key' => 'monthly_pension', 'label' => 'Monthly Pension (TZS)', 'type' => 'number', 'required' => true],
                    ['key' => 'pension_number', 'label' => 'Pension Number', 'type' => 'text', 'required' => false],
                ],
                'requires_extra_approval' => false,
            ],
        ];
    }
}
