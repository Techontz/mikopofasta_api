<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Customers\Enums\CustomerApprovalStatus;
use App\Domain\Customers\Enums\CustomerStatus;
use App\Domain\Customers\Enums\KycStatus;
use App\Domain\Customers\Services\CustomerNumberGenerator;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\User;
use Database\Seeders\Legacy\InferredLookups;
use Database\Seeders\Legacy\LegacySource;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;

/**
 * The eighteen customers the legacy system actually names.
 *
 * Every one of these people appears by name on a captured legacy screen — the
 * Loan Disbursed list or the Loan Pending Approve list. None is made up.
 *
 * Each row is part evidence and part inference, and the split is exact:
 *
 *   FROM THE SCREENSHOTS       Full name, spelled and cased as the legacy system
 *                              spells it. Branch. Phone number, for the ten who
 *                              appear on Loan Pending Approve.
 *
 *   INFERRED (see InferredLookups)
 *                              National ID, date of birth, gender, and a phone
 *                              for the other eight. Our schema requires all of
 *                              them; the All Customer screen would have supplied
 *                              the real values and was captured with no rows.
 *
 * The synthetic identifiers are deliberately marked rather than realistic. A
 * NIDA number here starts with eight zeroes, which no real Tanzanian one does,
 * so it can never be mistaken for a verified identity and can be found with a
 * single LIKE query when the real numbers arrive. Same for the eight invented
 * phone numbers, which sit outside any live mobile range.
 *
 * Runs after CustomerSeeder. That one seeds an invented book so the system has
 * volume to work with; this one seeds the real people. They coexist, and
 * `nida_number LIKE '00000000%'` tells them apart.
 */
final class LegacyCustomerSeeder extends Seeder
{
    public function run(): void
    {
        $numbers = app(CustomerNumberGenerator::class);

        $branches = Branch::query()->pluck('id', 'name');
        $officer = User::query()->where('phone', '0754000005')->first()
            ?? User::query()->orderBy('id')->first();

        // Every customer needs a category; which one is not something any
        // legacy screen says, so the first is used for all eighteen.
        $category = CustomerCategory::query()->orderBy('id')->first();

        if ($officer === null || $category === null || $branches->isEmpty()) {
            return;
        }

        $genders = InferredLookups::customerGenders();
        $now = Date::now();

        foreach (LegacySource::customers() as $index => $legacy) {
            $branchId = $branches[$legacy['branch']] ?? null;

            if ($branchId === null) {
                continue;
            }

            $nidaNumber = InferredLookups::syntheticNida($index + 1);

            if (Customer::query()->where('nida_number', $nidaNumber)->exists()) {
                continue;
            }

            [$first, $middle, $last] = $this->splitName($legacy['name']);

            Customer::query()->create([
                'customer_number' => $numbers->next(),

                // INFERRED — no legacy screen shows a national ID.
                'nida_number' => $nidaNumber,

                // FROM THE SCREENSHOTS — exact spelling and casing.
                'first_name' => $first,
                'middle_name' => $middle,
                'last_name' => $last,

                // INFERRED — one sentinel date for all eighteen, so the
                // customer list reads as obviously placeholder rather than as
                // eighteen convincing birthdays.
                'dob' => InferredLookups::PLACEHOLDER_DOB,

                // INFERRED from the given name. The least certain field here.
                'gender' => $genders[$legacy['name']] ?? InferredLookups::defaultGender(),

                // FROM THE SCREENSHOTS for the ten on Loan Pending Approve;
                // INFERRED and visibly synthetic for the other eight.
                'phone' => $legacy['phone'] ?? InferredLookups::syntheticPhone($index + 1),

                /*
                 * Not marked as NIDA/OTP/face verified. Verification is a claim
                 * that somebody checked this person against the registry, and
                 * stamping it here on identities we invented would be the one
                 * inference in this file with real consequences — it is exactly
                 * the check a loan approval relies on.
                 */
                'nida_verified_at' => null,
                'otp_verified_at' => null,
                'face_verified_at' => null,

                'customer_category_id' => $category->getKey(),
                'branch_id' => $branchId,

                'kyc_status' => KycStatus::Incomplete,

                /*
                 * The legacy Customer Status for these is NEW, which our schema
                 * has no equivalent of — CustomerStatus is active/suspended/
                 * frozen. Active is the honest mapping: NEW means "on the book,
                 * no history yet", not "restricted".
                 */
                'status' => CustomerStatus::Active,
                'approval_status' => CustomerApprovalStatus::NotRequired,

                'created_by' => $officer->getKey(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Split a legacy name into first, middle and last.
     *
     * All eighteen are three parts — given name, middle initial, family name —
     * so this splits on whitespace and treats anything between the first and
     * last token as the middle name. A two-part name would come through with a
     * null middle rather than losing a token.
     *
     * Casing is left exactly as the legacy system stores it, including the two
     * names recorded in lower case. Normalising them would be tidying evidence.
     *
     * @return array{string, string|null, string}
     */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return match (count($parts)) {
            0 => ['', null, ''],
            1 => [$parts[0], null, $parts[0]],
            2 => [$parts[0], null, $parts[1]],
            default => [
                $parts[0],
                implode(' ', array_slice($parts, 1, -1)),
                $parts[count($parts) - 1],
            ],
        };
    }
}
