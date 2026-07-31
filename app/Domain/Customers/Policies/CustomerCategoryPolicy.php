<?php

declare(strict_types=1);

namespace App\Domain\Customers\Policies;

use App\Domain\Auth\Enums\PermissionName;
use App\Models\CustomerCategory;
use App\Models\User;

/**
 * Categories are organization configuration, not customer data: the frontend
 * puts them under /admin/customer-categories and gates them on
 * `admin.org_settings`, not `customers.manage`.
 *
 * Reads stay open to any authenticated user — the registration wizard needs
 * the category list and its dynamic schema, and a Loan Officer holds no admin
 * permission.
 */
final class CustomerCategoryPolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function view(User $actor, CustomerCategory $category): bool
    {
        return true;
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::AdminOrgSettings);
    }

    public function update(User $actor, CustomerCategory $category): bool
    {
        return $actor->hasPermission(PermissionName::AdminOrgSettings);
    }

    public function delete(User $actor, CustomerCategory $category): bool
    {
        return $actor->hasPermission(PermissionName::AdminOrgSettings);
    }
}
