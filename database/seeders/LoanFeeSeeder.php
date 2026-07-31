<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Loans\Enums\ChargeValueType;
use App\Models\LoanFee;
use App\Models\LoanProduct;
use Illuminate\Database\Seeder;

/**
 * The arrangement fee charged on each loan product — Settings → Loan Fee.
 *
 * ## Where 5% comes from
 *
 * The legacy Loan Fee → Deducted Income screen, by way of the frontend's
 * `MOCK_DEDUCTED_INCOME` fixture, which records six rows read off it. Every one
 * is exactly 5% of the approved loan:
 *
 *     272,000 → 13,600      740,000 → 37,000      520,000 → 26,000
 *   6,000,000 → 300,000     650,000 → 32,500    1,200,000 → 60,000
 *
 * Six rows agreeing to the shilling across two orders of magnitude is not a
 * coincidence, so the rate is transcribed rather than invented — the same
 * standard `LegacySource` holds the rest of the legacy data to.
 *
 * ## Why insurance is zero
 *
 * `loan_fees.insurance_amount` exists because the legacy Loan Fee screen has an
 * Insurance column. No captured row shows a non-zero one, and the Deducted
 * Income figures above are explained in full by the fee alone — if a premium
 * were also being withheld, those six numbers would not be exactly 5%.
 *
 * So it is seeded at zero: recorded as a real column that currently carries
 * nothing, rather than given a plausible figure that would quietly change what
 * every borrower receives. See docs/modules/penalties-and-fees.md on what
 * charging one would mean for the posting.
 */
final class LoanFeeSeeder extends Seeder
{
    /** Transcribed: see the class docblock. */
    private const LEGACY_FEE_PERCENT = '5.00';

    public function run(): void
    {
        foreach (LoanProduct::query()->get() as $product) {
            LoanFee::query()->updateOrCreate(
                ['loan_product_id' => $product->getKey()],
                [
                    'fee_type' => ChargeValueType::PercentageValue,
                    'fee_amount' => self::LEGACY_FEE_PERCENT,
                    'insurance_amount' => '0.00',
                ],
            );
        }
    }
}
