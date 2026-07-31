<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SalaryAdvanceCategory;
use Illuminate\Database\Seeder;

/**
 * The salary advance bands — Salary Advance → Salary Advance Category.
 *
 * ## Provenance
 *
 * The legacy Salary Advance Category screen was not captured, so unlike the
 * loan fee's 5% there is no transcribed figure to reproduce. What IS known from
 * the captures is the shape: the frontend's `SalaryAdvanceCategorySchema` has
 * name, interest rate, an amount band and a charge fee, and those five columns
 * came off the legacy form.
 *
 * So the columns are evidence and the values are not. They are set to a plainly
 * conservative, internally coherent ladder — larger advances cost slightly more
 * and run slightly longer — and are marked here as the placeholder they are.
 * The alternative was an empty table, which would make the request flow
 * unusable, since an advance cannot be priced without a band.
 *
 * Replace these the moment the real bands are known. Nothing else depends on
 * the numbers: the terms are snapshotted onto each advance at request, so
 * changing a band re-prices only future requests.
 *
 * @see docs/modules/salary-advance.md
 */
final class SalaryAdvanceCategorySeeder extends Seeder
{
    /**
     * name, interest %, from, to, charge fee, recovery periods.
     *
     * The bands do not overlap and leave no gap between 10,000 and 5,000,000 —
     * a request landing in a gap could not be priced at all, and one landing in
     * an overlap would be priced by whichever band was found first.
     *
     * @var list<array{string, string, string, string, string, int}>
     */
    private const BANDS = [
        ['Small Advance', '5.000', '10000.00', '200000.00', '2000.00', 1],
        ['Standard Advance', '7.500', '200000.01', '500000.00', '5000.00', 2],
        ['Large Advance', '10.000', '500000.01', '1000000.00', '10000.00', 3],
        ['Executive Advance', '12.500', '1000000.01', '5000000.00', '20000.00', 6],
    ];

    public function run(): void
    {
        foreach (self::BANDS as [$name, $rate, $from, $to, $fee, $periods]) {
            SalaryAdvanceCategory::query()->updateOrCreate(
                ['name' => $name],
                [
                    'interest_rate' => $rate,
                    'from_amount' => $from,
                    'to_amount' => $to,
                    'charge_fee' => $fee,
                    'recovery_periods' => $periods,
                ],
            );
        }
    }
}
