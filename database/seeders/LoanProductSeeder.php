<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Loans\Enums\InterestFormulaCode;
use App\Domain\Loans\Enums\PenaltyType;
use App\Enums\ActiveStatus;
use App\Models\CategoryProductEligibility;
use App\Models\CustomerCategory;
use App\Models\InterestFormula;
use App\Models\LoanProduct;
use App\Models\RepaymentSchedule;
use Illuminate\Database\Seeder;

/**
 * The loan configuration layer — formulas, cadences, products, and the two
 * §2.3 pivots.
 *
 * Mirrors the frontend's MOCK_INTEREST_FORMULAS, MOCK_REPAYMENT_SCHEDULES,
 * MOCK_LOAN_PRODUCTS, MOCK_LOAN_PRODUCT_REPAYMENT_SCHEDULES and
 * MOCK_CATEGORY_PRODUCT_ELIGIBILITY, so the same products with the same terms
 * exist on both sides.
 *
 * Note the Salary Advance product: `flat_fee` with a penalty_rate of 10,000
 * TZS. That single row is what makes OSC-2 concrete — it does not fit the
 * DECIMAL(6,3) the spec names for the column.
 */
final class LoanProductSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedFormulas();
        $this->seedSchedules();
        $this->seedProducts();
        $this->seedEligibility();
    }

    private function seedFormulas(): void
    {
        /*
         * "Formula", not "Formular". These are labels we author and render, so
         * they fall under the spelling sweep — unlike a legacy lookup value
         * that has to keep matching the old system's records. The frontend was
         * corrected in that sweep and this was missed, which left the two
         * repositories disagreeing on the name of the same three rows.
         */
        $formulas = [
            [
                'name' => 'Simple Formula',
                'code' => InterestFormulaCode::Simple,
                'description' => 'Interest computed once on the original principal for the full tenure.',
            ],
            [
                'name' => 'Flat Rate Formula',
                'code' => InterestFormulaCode::Flat,
                'description' => 'Interest charged per installment on the original principal.',
            ],
            [
                'name' => 'Reducing Formula',
                'code' => InterestFormulaCode::Reducing,
                'description' => 'Interest charged per installment on the outstanding balance.',
            ],
        ];

        foreach ($formulas as $formula) {
            InterestFormula::query()->updateOrCreate(['code' => $formula['code']], $formula);
        }
    }

    private function seedSchedules(): void
    {
        /*
         * Daily, Weekly and Monthly are the legacy Restoration Types, and that
         * list is complete — the Loan Withdrawal filter tabs enumerate the whole
         * set. See LegacySource::restorationTypes().
         *
         * Group is ours, not the legacy system's. It is the cadence the Group
         * Solidarity product runs on, so it stays; it is flagged here so nobody
         * later reads all four as transcribed.
         */
        $schedules = [
            ['name' => 'Daily', 'code' => 'DAILY', 'frequency_days' => 1],
            ['name' => 'Weekly', 'code' => 'WEEKLY', 'frequency_days' => 7],
            ['name' => 'Monthly', 'code' => 'MONTHLY', 'frequency_days' => 30],
            ['name' => 'Group', 'code' => 'GROUP', 'frequency_days' => 7],  // INFERRED — not a legacy Restoration Type
        ];

        foreach ($schedules as $schedule) {
            RepaymentSchedule::query()->updateOrCreate(['code' => $schedule['code']], $schedule);
        }
    }

    private function seedProducts(): void
    {
        $formulas = InterestFormula::query()->pluck('id', 'code');
        $schedules = RepaymentSchedule::query()->pluck('id', 'code');

        foreach ($this->products() as $definition) {
            $allowedCodes = $definition['schedules'];

            /*
             * Built explicitly rather than by mutating $definition: the source
             * array carries two transient keys (`schedules` and
             * `interest_formula_code`) that are not columns, and unsetting
             * them leaves an array whose shape no longer matches the table.
             */
            $columns = [
                'name' => $definition['name'],
                'code' => $definition['code'],
                'interest_formula_id' => $formulas[$definition['interest_formula_code']->value],
                'interest_rate' => $definition['interest_rate'],
                'min_amount' => $definition['min_amount'],
                'max_amount' => $definition['max_amount'],
                'min_tenure_days' => $definition['min_tenure_days'],
                'max_tenure_days' => $definition['max_tenure_days'],
                'penalty_type' => $definition['penalty_type'],
                'penalty_rate' => $definition['penalty_rate'],
                'penalty_grace_days' => $definition['penalty_grace_days'],
                'penalty_cap_amount' => $definition['penalty_cap_amount'],
                'requires_mandate' => $definition['requires_mandate'],
                'status' => $definition['status'],
            ];

            $product = LoanProduct::query()->updateOrCreate(['code' => $definition['code']], $columns);

            $product->repaymentSchedules()->sync(
                array_map(static fn (string $code): int => $schedules[$code], $allowedCodes),
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function products(): array
    {
        return [
            [
                'name' => 'Boda Boda Working Capital',
                'code' => 'BODA_WC',
                'interest_formula_code' => InterestFormulaCode::Reducing,
                'interest_rate' => '8.000',
                'min_amount' => '100000.00',
                'max_amount' => '1000000.00',
                'min_tenure_days' => 30,
                'max_tenure_days' => 180,
                'penalty_type' => PenaltyType::PercentageOfOverdue,
                'penalty_rate' => '5.000',
                'penalty_grace_days' => 3,
                'penalty_cap_amount' => '50000.00',
                'requires_mandate' => false,
                'status' => ActiveStatus::Active,
                'schedules' => ['DAILY', 'WEEKLY'],
            ],
            [
                'name' => 'Entrepreneur Growth Loan',
                'code' => 'SME_GROWTH',
                'interest_formula_code' => InterestFormulaCode::Simple,
                'interest_rate' => '12.000',
                'min_amount' => '300000.00',
                'max_amount' => '5000000.00',
                'min_tenure_days' => 60,
                'max_tenure_days' => 365,
                'penalty_type' => PenaltyType::PercentageOfOverdue,
                'penalty_rate' => '4.000',
                'penalty_grace_days' => 5,
                'penalty_cap_amount' => '200000.00',
                'requires_mandate' => false,
                'status' => ActiveStatus::Active,
                'schedules' => ['WEEKLY', 'MONTHLY'],
            ],
            [
                // The OSC-2 case: penalty_rate here is 10,000 TZS, not 10%.
                'name' => 'Salary Advance E-Mandate',
                'code' => 'SALARY_ADVANCE',
                'interest_formula_code' => InterestFormulaCode::Flat,
                'interest_rate' => '3.000',
                'min_amount' => '200000.00',
                'max_amount' => '3000000.00',
                'min_tenure_days' => 30,
                'max_tenure_days' => 365,
                'penalty_type' => PenaltyType::FlatFee,
                'penalty_rate' => '10000.000',
                'penalty_grace_days' => 0,
                'penalty_cap_amount' => null,
                'requires_mandate' => true,
                'status' => ActiveStatus::Active,
                'schedules' => ['MONTHLY'],
            ],
            [
                'name' => 'Public Servant Loan',
                'code' => 'PUBLIC_SERVANT_LOAN',
                'interest_formula_code' => InterestFormulaCode::Reducing,
                'interest_rate' => '9.000',
                'min_amount' => '500000.00',
                'max_amount' => '8000000.00',
                'min_tenure_days' => 90,
                'max_tenure_days' => 730,
                'penalty_type' => PenaltyType::PercentagePerDay,
                'penalty_rate' => '0.500',
                'penalty_grace_days' => 7,
                'penalty_cap_amount' => '300000.00',
                'requires_mandate' => true,
                'status' => ActiveStatus::Active,
                'schedules' => ['MONTHLY'],
            ],
            [
                'name' => 'Group Solidarity Loan',
                'code' => 'GROUP_SOLIDARITY',
                'interest_formula_code' => InterestFormulaCode::Flat,
                'interest_rate' => '2.500',
                'min_amount' => '50000.00',
                'max_amount' => '500000.00',
                'min_tenure_days' => 28,
                'max_tenure_days' => 168,
                'penalty_type' => PenaltyType::PercentageOfOverdue,
                'penalty_rate' => '6.000',
                'penalty_grace_days' => 2,
                'penalty_cap_amount' => '25000.00',
                'requires_mandate' => false,
                'status' => ActiveStatus::Active,
                'schedules' => ['WEEKLY', 'GROUP'],
            ],
        ];
    }

    /**
     * The category → product rule engine (§2.3): which categories may apply
     * for which products, and on what terms.
     *
     * Boda Boda is capped below the product maximum to exercise
     * `max_amount_override`; the high-risk category cannot reach the large
     * salaried products at all.
     */
    private function seedEligibility(): void
    {
        $categories = CustomerCategory::query()->pluck('id', 'code');
        $products = LoanProduct::query()->pluck('id', 'code');

        $rules = [
            ['BODA', 'BODA_WC', '600000.00', false],
            ['BODA', 'GROUP_SOLIDARITY', null, false],

            ['SME_SMALL', 'BODA_WC', null, false],
            ['SME_SMALL', 'SME_GROWTH', '2000000.00', false],
            ['SME_SMALL', 'GROUP_SOLIDARITY', null, false],

            ['SME_MEDIUM', 'SME_GROWTH', null, true],

            ['PUBLIC_SERVANT', 'PUBLIC_SERVANT_LOAN', null, false],
            ['PUBLIC_SERVANT', 'SALARY_ADVANCE', null, false],

            ['PRIVATE_SECTOR', 'SALARY_ADVANCE', null, false],
            ['PRIVATE_SECTOR', 'SME_GROWTH', '3000000.00', false],
        ];

        foreach ($rules as [$categoryCode, $productCode, $override, $extraApproval]) {
            if (! isset($categories[$categoryCode], $products[$productCode])) {
                continue;
            }

            CategoryProductEligibility::query()->updateOrCreate(
                [
                    'customer_category_id' => $categories[$categoryCode],
                    'loan_product_id' => $products[$productCode],
                ],
                [
                    'max_amount_override' => $override,
                    'requires_extra_approval' => $extraApproval,
                ],
            );
        }
    }
}
