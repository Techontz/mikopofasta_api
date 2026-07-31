<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Auth\Support\RolePermissionMatrix;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the eleven roles from §14 and attaches their default grants.
 *
 * Runs after PermissionSeeder. Roles are created idempotently, but their
 * grants are SYNCED — so re-seeding restores the §14 defaults and discards any
 * runtime edits made through the permission matrix. That is the intended
 * behaviour for a seeder ("reset to the specified baseline"), and it is why
 * seeding is not something to run casually against a live database.
 */
final class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (RoleName::cases() as $roleName) {
            $role = Role::findOrCreate($roleName->value, 'web');

            $permissions = Permission::query()
                ->whereIn('name', RolePermissionMatrix::for($roleName))
                ->get();

            $role->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
