<?php

declare(strict_types=1);

namespace App\Domain\Auth\Policies;

use App\Domain\Auth\Enums\PermissionName;
use App\Models\Role;
use App\Models\User;

/**
 * Authorization for the roles and permission-matrix endpoints.
 *
 * `roles.view` and `roles.manage` are deliberately separate grants (§14):
 * Admin holds ROLES_VIEW and can inspect the matrix, but only Super Admin
 * holds ROLES_MANAGE and can change it. That split is what stops an
 * administrator from granting themselves ledger-reversal approval.
 */
final class RolePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::RolesView);
    }

    public function view(User $actor, Role $role): bool
    {
        return $actor->hasPermission(PermissionName::RolesView);
    }

    public function updatePermissions(User $actor, Role $role): bool
    {
        return $actor->hasPermission(PermissionName::RolesManage);
    }
}
