<?php

declare(strict_types=1);

namespace App\Domain\Customers\Policies;

use App\Domain\Auth\Enums\PermissionName;
use App\Models\Group;
use App\Models\User;

/**
 * Authorization for groups.
 *
 * A group is a set of customers, so it rides on the customer grants rather than
 * introducing a fourth: whoever may register and edit customers may form a
 * group and move members between roles. Closing a group is the one destructive
 * act and is held to the same `customers.manage` bar — it cannot orphan a loan,
 * because GroupService refuses while money is outstanding.
 *
 * Branch scope (§13) is not enforced here, for the same reason as
 * CustomerPolicy: a scope failure must surface as BRANCH_SCOPE_VIOLATION and be
 * audited, and a policy can only answer yes or no.
 */
final class GroupPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::CustomersView);
    }

    public function view(User $actor, Group $group): bool
    {
        return $actor->hasPermission(PermissionName::CustomersView);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::CustomersManage);
    }

    public function update(User $actor, Group $group): bool
    {
        return $actor->hasPermission(PermissionName::CustomersManage);
    }

    public function delete(User $actor, Group $group): bool
    {
        return $actor->hasPermission(PermissionName::CustomersManage);
    }

    /** Adding, removing and re-roling members. */
    public function manageMembers(User $actor, Group $group): bool
    {
        return $actor->hasPermission(PermissionName::CustomersManage);
    }
}
