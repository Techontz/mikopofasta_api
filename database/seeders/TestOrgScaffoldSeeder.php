<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Organization\Enums\BranchType;
use App\Enums\ActiveStatus;
use App\Models\Branch;
use App\Models\Region;
use App\Models\Zone;
use Illuminate\Database\Seeder;

/**
 * Organizational structure that exists only so the test suite can exercise it.
 *
 * Three features of our schema have no legacy counterpart we have ever seen,
 * and therefore no legacy data to seed:
 *
 *   - the branch hierarchy — a sub-branch rolling up into a main branch (§12);
 *   - zones — a zone manager seeing every branch in their zone (§13);
 *   - which region a branch sits in — the same scoping rule, one level up.
 *
 * All three are real, all three are covered by tests, and none can be covered
 * without a parent branch, a zone and a region to point at.
 *
 * They used to live in OrganizationSeeder, which meant an invented branch named
 * "Lindi", an invented "West Zone"/"East Zone" split and invented region
 * assignments all shipped as though they were part of the business's actual
 * structure. They are scaffolding, so they live in a seeder that says so and
 * that only the test bootstrap calls.
 *
 * Nothing here is claimed to reflect the legacy system. If the real branch list,
 * zone structure and regions are ever captured, this file is what they replace.
 */
final class TestOrgScaffoldSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['West Zone', 'East Zone'] as $name) {
            Zone::query()->firstOrCreate(['name' => $name]);
        }

        $west = Zone::query()->where('name', 'West Zone')->value('id');
        $east = Zone::query()->where('name', 'East Zone')->value('id');

        $regions = Region::query()->pluck('id', 'name');

        // A second main branch, so there is something for a sub-branch to roll
        // up into and something outside the zone under test.
        $lindi = Branch::query()->updateOrCreate(
            ['name' => 'Lindi'],
            [
                'region_id' => $regions['Lindi'] ?? null,
                'zone_id' => $east,
                // A phone, because branch writes require one and the hierarchy
                // tests round-trip an existing branch through PUT /branches.
                'phone' => '0700000004',
                'type' => BranchType::Main,
                'parent_branch_id' => null,
                'is_head_office' => false,
                'status' => ActiveStatus::Active,
            ],
        );

        // NEW KALENGE becomes the sub-branch. It is a real legacy branch; the
        // parent relationship is the invented part, and only the tests see it.
        Branch::query()->where('name', 'NEW KALENGE')->update([
            'region_id' => $regions['Mbeya'] ?? null,
            'zone_id' => $east,
            'phone' => '0700000005',
            'type' => BranchType::Sub,
            'parent_branch_id' => $lindi->getKey(),
        ]);

        Branch::query()->where('name', 'Kakonko')->update([
            'region_id' => $regions['Kigoma'] ?? null,
            'zone_id' => $west,
            'phone' => '0700000002',
        ]);

        Branch::query()->where('name', 'Missenyi')->update([
            'region_id' => $regions['Kagera'] ?? null,
            'zone_id' => $west,
            'phone' => '0700000003',
        ]);

        Branch::query()->where('is_head_office', true)->update([
            'region_id' => $regions['Mwanza'] ?? null,
            'phone' => '0700000001',
        ]);
    }
}
