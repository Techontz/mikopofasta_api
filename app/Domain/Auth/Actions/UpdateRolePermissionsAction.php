<?php

declare(strict_types=1);

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Exceptions\RoleNotEditableException;
use App\Enums\AuditAction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Replaces a role's permission grants (PUT /roles/{role}/permissions).
 *
 * The frontend's permission matrix toggles one checkbox at a time but computes
 * the resulting full set before calling through (see toggleRolePermission in
 * features/admin/roles/roles-actions.ts), so the endpoint takes the complete
 * list. That also makes it idempotent, and immune to two administrators
 * toggling different boxes from a stale view and silently losing one change.
 */
final class UpdateRolePermissionsAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param list<string> $permissionNames
     */
    public function handle(Role $role, array $permissionNames, User $actor): Role
    {
        if (! $role->isEditable()) {
            throw new RoleNotEditableException;
        }

        return DB::transaction(function () use ($role, $permissionNames, $actor): Role {
            $before = $role->permissions()->pluck('name')->sort()->values()->all();

            $permissions = Permission::query()
                ->whereIn('name', $permissionNames)
                ->get();

            $role->syncPermissions($permissions);

            $after = $role->load('permissions')->permissions->pluck('name')->sort()->values()->all();

            $this->audit->log(
                AuditAction::RolePermissionsUpdated,
                $role,
                before: ['permissions' => $before],
                after: ['permissions' => $after],
                actor: $actor,
            );

            return $role;
        });
    }
}
