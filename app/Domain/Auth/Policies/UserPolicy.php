<?php

declare(strict_types=1);

namespace App\Domain\Auth\Policies;

use App\Domain\Auth\Enums\PermissionName;
use App\Models\User;

/**
 * Authorization for the user-management endpoints.
 *
 * Every gate here keys off `users.manage`, matching the frontend's
 * requirePermission() in features/admin/users/users-actions.ts. The
 * self-modification rules are business invariants rather than permission
 * checks, so they live in the actions where they throw a specific,
 * frontend-recognisable error code — a policy can only answer yes or no.
 */
final class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::UsersManage);
    }

    public function view(User $actor, User $user): bool
    {
        // A user can always read their own record, which is what
        // GET /auth/me resolves to.
        return $actor->is($user) || $actor->hasPermission(PermissionName::UsersManage);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::UsersManage);
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->hasPermission(PermissionName::UsersManage);
    }

    public function updateStatus(User $actor, User $user): bool
    {
        return $actor->hasPermission(PermissionName::UsersManage);
    }

    public function delete(User $actor, User $user): bool
    {
        return $actor->hasPermission(PermissionName::UsersManage);
    }
}
