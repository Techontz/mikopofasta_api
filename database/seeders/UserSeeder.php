<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Auth\Enums\PermissionName;
use App\Domain\Auth\Enums\RoleName;
use App\Domain\Auth\Enums\UserStatus;
use App\Models\Branch;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The eleven demo accounts, one per role.
 *
 * Names, phone numbers, roles, the derived email pattern and now the
 * branch/zone/region assignments all mirror the frontend's SEED_USERS in
 * lib/mock-data/users.ts, so the demo roster in the frontend's
 * docs/demo-accounts.md works unchanged against the real API.
 *
 * Every user has a home branch, including the HQ-wide roles — they are based
 * at Head Office (spec §12 Decision 2). Cross-branch visibility is decided by
 * the `branches.view_all` permission, never by branch_id being absent.
 *
 * Runs after OrganizationSeeder, which creates the branches and zones this
 * references.
 */
final class UserSeeder extends Seeder
{
    /**
     * Shared password for every demo account, matching the frontend's mock
     * credential set. Development convenience only.
     */
    private const string DEMO_PASSWORD = 'password';

    public function run(): void
    {
        $roles = Role::query()->pluck('id', 'name');
        $branches = Branch::query()->pluck('id', 'name');
        $zones = Zone::query()->pluck('id', 'name');
        $regions = Region::query()->pluck('id', 'name');

        $superAdmin = null;

        foreach ($this->accounts() as $account) {
            $user = User::withTrashed()->updateOrCreate(
                ['phone' => $account['phone']],
                [
                    'name' => $account['name'],
                    'email' => $this->emailFor($account['name']),
                    'password' => self::DEMO_PASSWORD,
                    'role_id' => $roles[$account['role']->value],
                    'branch_id' => $branches[$account['branch']] ?? null,
                    'zone_id' => $account['zone'] === null ? null : ($zones[$account['zone']] ?? null),
                    'region_id' => $account['region'] === null ? null : ($regions[$account['region']] ?? null),
                    'status' => UserStatus::Active,
                    'created_by' => $superAdmin?->getKey(),
                    'deleted_at' => null,
                ],
            );

            $superAdmin ??= $user;

            /*
             * Per-user grants layered on top of the role. The Zone Manager
             * carries LOANS_REVIEW_CROSS_BRANCH to demonstrate §13/§14
             * Decision 1: cross-branch loan review is always an explicit,
             * visible grant and is never bundled into a role.
             */
            $user->syncPermissions($account['extraPermissions']);
        }

        $this->attachZoneManagers();
    }

    /**
     * Close the zone-manager link on whichever zone the zone manager sits in.
     *
     * The demo seed no longer creates zones — the legacy system has never shown
     * us one, so inventing a West/East split was removed along with the
     * invented branches. Zones remain a real feature; this simply does nothing
     * until something creates one, which in practice is TestOrgScaffoldSeeder.
     */
    private function attachZoneManagers(): void
    {
        $zoneManager = User::query()->where('phone', '0754000008')->first();

        if ($zoneManager === null || $zoneManager->zone_id === null) {
            return;
        }

        Zone::query()->whereKey($zoneManager->zone_id)->update(['zone_manager_id' => $zoneManager->getKey()]);
    }

    /**
     * @return list<array{
     *     name: string, phone: string, role: RoleName, branch: string,
     *     zone: string|null, region: string|null, extraPermissions: list<string>
     * }>
     */
    private function accounts(): array
    {
        return [
            [
                'name' => 'Amina Juma', 'phone' => '0754000001', 'role' => RoleName::SuperAdmin,
                'branch' => 'Head Office', 'zone' => null, 'region' => null, 'extraPermissions' => [],
            ],
            [
                'name' => 'Baraka Mushi', 'phone' => '0754000002', 'role' => RoleName::Admin,
                'branch' => 'Head Office', 'zone' => null, 'region' => null, 'extraPermissions' => [],
            ],
            [
                'name' => 'Catherine Massawe', 'phone' => '0754000003', 'role' => RoleName::Finance,
                'branch' => 'Head Office', 'zone' => null, 'region' => null, 'extraPermissions' => [],
            ],
            [
                'name' => 'Daniel Kessy', 'phone' => '0754000004', 'role' => RoleName::BranchManager,
                'branch' => 'Kakonko', 'zone' => null, 'region' => null, 'extraPermissions' => [],
            ],
            [
                'name' => 'Esther Mollel', 'phone' => '0754000005', 'role' => RoleName::LoanOfficer,
                'branch' => 'Kakonko', 'zone' => null, 'region' => null, 'extraPermissions' => [],
            ],
            [
                'name' => 'Frank Urio', 'phone' => '0754000006', 'role' => RoleName::CreditOfficer,
                'branch' => 'Missenyi', 'zone' => null, 'region' => null, 'extraPermissions' => [],
            ],
            [
                'name' => 'Grace Mbwana', 'phone' => '0754000007', 'role' => RoleName::Hr,
                'branch' => 'Head Office', 'zone' => null, 'region' => null, 'extraPermissions' => [],
            ],
            [
                'name' => 'Hamisi Ally', 'phone' => '0754000008', 'role' => RoleName::ZoneManager,
                'branch' => 'Kakonko', 'zone' => 'West Zone', 'region' => null,
                'extraPermissions' => [PermissionName::LoansReviewCrossBranch->value],
            ],
            [
                'name' => 'Irene Komba', 'phone' => '0754000009', 'role' => RoleName::RegionalManager,
                'branch' => 'Missenyi', 'zone' => null, 'region' => 'Kagera', 'extraPermissions' => [],
            ],
            [
                'name' => 'Joseph Mrema', 'phone' => '0754000010', 'role' => RoleName::Teller,
                'branch' => 'NEW KALENGE', 'zone' => null, 'region' => null, 'extraPermissions' => [],
            ],
            [
                'name' => 'Khadija Ramadhani', 'phone' => '0754000011', 'role' => RoleName::Auditor,
                'branch' => 'Head Office', 'zone' => null, 'region' => null, 'extraPermissions' => [],
            ],
        ];
    }

    /**
     * Reproduces the frontend's derivation:
     * lowercase, strip non-letters, spaces to dots.
     */
    private function emailFor(string $name): string
    {
        $local = Str::of($name)
            ->lower()
            ->replaceMatches('/[^a-z\s]/', '')
            ->replaceMatches('/\s+/', '.')
            ->value();

        return $local.'@mikopofasta.co.tz';
    }
}
