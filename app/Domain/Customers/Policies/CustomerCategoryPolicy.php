<?php

declare(strict_types=1);

namespace App\Domain\Customers\Policies;

use App\Models\CustomerCategory;
use App\Models\User;

/**
 * Categories are the classification system itself, and only the Super Admin
 * configures it.
 *
 * WRITES ARE SUPER ADMIN ONLY. They used to be gated on `admin.org_settings`,
 * which the Admin role also holds — so an Admin could rename a category, change
 * which documents it demands, or delete one. A category is not an ordinary
 * setting: it decides what registration asks of a customer, which documents
 * their file must contain, and which loan products they may take. Changing one
 * silently re-scopes the customers already filed under it, so it belongs to the
 * one role that owns the institution's configuration outright.
 *
 * `isSuperAdmin()` rather than a new permission row: a permission can be granted
 * to another role through the matrix, and this rule is meant to be one that
 * cannot be delegated. Gate::before in AppServiceProvider already answers true
 * for Super Admin before any policy runs, so this method is what everybody else
 * is measured by.
 *
 * Reads stay open to any authenticated user — the registration wizard needs the
 * category list and its dynamic schema, and a Loan Officer holds no admin
 * permission. Operational users SELECT from the classification; they never
 * change it.
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
        return $actor->isSuperAdmin();
    }

    public function update(User $actor, CustomerCategory $category): bool
    {
        return $actor->isSuperAdmin();
    }

    public function delete(User $actor, CustomerCategory $category): bool
    {
        return $actor->isSuperAdmin();
    }
}
