<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Auth\Enums\PermissionName;
use App\Models\Permission;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the 29 permission strings from §14.
 *
 * Idempotent (firstOrCreate), so re-running it on an existing database adds
 * any newly-defined permission without disturbing the grants already attached
 * to roles.
 */
final class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
