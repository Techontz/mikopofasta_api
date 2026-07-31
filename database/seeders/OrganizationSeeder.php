<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Organization\Enums\BranchType;
use App\Enums\ActiveStatus;
use App\Models\Branch;
use App\Models\CompanyProfile;
use App\Models\Region;
use Database\Seeders\Legacy\InferredLookups;
use Database\Seeders\Legacy\LegacySource;
use Illuminate\Database\Seeder;

/**
 * Branches and the company profile.
 *
 * The branches are the three the legacy system actually shows — NEW KALENGE,
 * Missenyi and Kakonko, spelled and cased exactly as it spells them. See
 * LegacySource::branches() for where each was read from.
 *
 * Three things this seeder used to create are now gone, because no legacy
 * screen supports them:
 *
 *   - "Lindi" was invented outright, as was New Kalenge rolling up into it as a
 *     sub-branch. Both are removed; all three legacy branches are main
 *     branches, which is the only thing the evidence permits.
 *   - The West/East zone split was invented. Removed. Zones remain a real
 *     feature of our schema; there is simply no legacy zone data to seed.
 *   - Region assignments and phone numbers were invented. Left null — the
 *     legacy branch screen has never been captured, so neither is known.
 *
 * Head Office is the one branch here with no legacy evidence behind its name,
 * and it is kept deliberately. §12 Decision 2 makes HQ a branch row flagged
 * `is_head_office` rather than a separate table, so the ledger, expense tagging
 * and staff assignment all depend on such a row existing; the legacy system has
 * a headquarters too (its HQ expense and HQ transaction modules), we just have
 * never seen what it calls the branch. It is marked below so nobody later
 * mistakes it for a transcribed value.
 *
 * Runs after GeographySeeder and before UserSeeder assigns people to branches.
 */
final class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $regions = Region::query()->pluck('id', 'name');
        $inferredRegions = InferredLookups::branchRegions();
        $inferredPhones = InferredLookups::branchPhones();

        /*
         * Structural, not transcribed. See the class docblock: our ledger needs
         * a head-office branch to exist, and the legacy name for it is unknown.
         */
        Branch::query()->updateOrCreate(
            ['name' => 'Head Office'],
            [
                'region_id' => $regions[$inferredRegions['Head Office']] ?? null,
                'zone_id' => null,
                'phone' => $inferredPhones['Head Office'],
                'type' => BranchType::Main,
                'parent_branch_id' => null,
                'is_head_office' => true,
                'status' => ActiveStatus::Active,
            ],
        );

        foreach (LegacySource::branches() as $name) {
            Branch::query()->updateOrCreate(
                ['name' => $name],
                [
                    /*
                     * Name: transcribed. Region and phone: INFERRED — see
                     * InferredLookups, which explains the reasoning for each.
                     * No captured legacy screen shows either, but both are load
                     * bearing: regional-manager scoping (§13) reads the region,
                     * and the branch edit form will not submit without a phone.
                     */
                    'region_id' => $regions[$inferredRegions[$name] ?? ''] ?? null,
                    'zone_id' => null,
                    'phone' => $inferredPhones[$name] ?? null,
                    'type' => BranchType::Main,
                    'parent_branch_id' => null,
                    'is_head_office' => false,
                    'status' => ActiveStatus::Active,
                ],
            );
        }

        $headOfficeId = Branch::query()->where('is_head_office', true)->value('id');

        /*
         * Only the trading name is evidenced — it is the wordmark in the top
         * left of every legacy screen, and the domain the system is served
         * from.
         *
         * The registration number, TIN, phone and postal address that used to
         * be here were fabricated. They are blanked rather than replaced,
         * because these are the fields that end up printed on a loan agreement:
         * an empty registration number is visibly unset and someone will fill
         * it in, whereas "REG-2019-004821" looks like a fact and nobody
         * questions it. The columns are NOT NULL, so empty string is how
         * "unknown" is spelled here until the real values are supplied through
         * the company profile screen.
         */
        CompanyProfile::query()->updateOrCreate(
            ['id' => 1],
            [
                'legal_name' => '',
                'trading_name' => 'MikopoFasta',
                'registration_number' => '',
                'tin_number' => '',
                'phone' => '',
                'email' => '',
                'address' => '',
                'headquarters_branch_id' => $headOfficeId,
            ],
        );
    }
}
