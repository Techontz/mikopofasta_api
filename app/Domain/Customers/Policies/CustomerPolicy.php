<?php

declare(strict_types=1);

namespace App\Domain\Customers\Policies;

use App\Domain\Auth\Enums\PermissionName;
use App\Models\Customer;
use App\Models\User;

/**
 * Authorization for customers, mirroring the frontend's three gates in
 * features/customers/actions.ts:
 *
 *   customers.view    read the list and profiles
 *   customers.manage  register, edit, notes, documents, freeze, suspend
 *   customers.approve approve or reject a pending registration
 *
 * Branch scope (§13) is NOT enforced here. As with branches in Phase 3, a
 * scope failure has to surface as BRANCH_SCOPE_VIOLATION and be audited, and a
 * policy can only answer yes or no — BranchScopeGuard does both.
 */
final class CustomerPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::CustomersView);
    }

    public function view(User $actor, Customer $customer): bool
    {
        return $actor->hasPermission(PermissionName::CustomersView);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::CustomersManage);
    }

    public function update(User $actor, Customer $customer): bool
    {
        return $actor->hasPermission(PermissionName::CustomersManage);
    }

    /**
     * Approving is a separate grant from managing: a Loan Officer registers
     * customers but may not wave through one whose category demands extra
     * scrutiny (§14).
     */
    public function decideApproval(User $actor, Customer $customer): bool
    {
        return $actor->hasPermission(PermissionName::CustomersApprove);
    }

    public function freeze(User $actor, Customer $customer): bool
    {
        return $actor->hasPermission(PermissionName::CustomersManage);
    }
}
