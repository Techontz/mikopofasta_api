<?php

declare(strict_types=1);

namespace App\Domain\Organization\Policies;

use App\Domain\Auth\Enums\PermissionName;
use App\Models\Branch;
use App\Models\User;

/**
 * Authorization for branches.
 *
 * Reads and writes are gated differently, and deliberately so:
 *
 *  - READ is open to any authenticated user. Branches are reference data the
 *    whole app depends on — the branch switcher, the customer registration
 *    wizard's home-branch field, every branch filter. *Which* branches come
 *    back is then narrowed by BranchScope (§13), so a Teller reading the list
 *    still only ever sees their own branch.
 *  - WRITE requires `admin.org_settings`, matching the frontend's
 *    requirePermission() in features/admin/organization/branches-actions.ts.
 */
final class BranchPolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    /**
     * Permission only — the scope check is deliberately NOT here.
     *
     * §13 requires a scope failure to surface as `BRANCH_SCOPE_VIOLATION` and
     * to be written to `audit_logs`. A policy can only answer yes or no, so
     * refusing here would produce a generic `FORBIDDEN` with no audit row and
     * the specific requirement would be silently lost. BranchScopeGuard runs
     * immediately after this and does both.
     */
    public function view(User $actor, Branch $branch): bool
    {
        return true;
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::AdminOrgSettings);
    }

    public function update(User $actor, Branch $branch): bool
    {
        return $actor->hasPermission(PermissionName::AdminOrgSettings);
    }

    public function delete(User $actor, Branch $branch): bool
    {
        return $actor->hasPermission(PermissionName::AdminOrgSettings);
    }

    public function setHeadOffice(User $actor, Branch $branch): bool
    {
        return $actor->hasPermission(PermissionName::AdminOrgSettings);
    }
}
