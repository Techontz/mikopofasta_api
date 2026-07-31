<?php

declare(strict_types=1);

namespace App\Domain\Loans\Policies;

use App\Domain\Auth\Enums\PermissionName;
use App\Models\LoanProduct;
use App\Models\User;

/**
 * Loan products are organization configuration: the frontend files them under
 * /admin/loan-products and gates writes on `admin.org_settings`.
 *
 * Reads stay open to any authenticated user — the loan application form needs
 * the product list, its limits and its allowed cadences to validate anything,
 * and a Loan Officer holds no admin permission.
 */
final class LoanProductPolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function view(User $actor, LoanProduct $product): bool
    {
        return true;
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::AdminOrgSettings);
    }

    public function update(User $actor, LoanProduct $product): bool
    {
        return $actor->hasPermission(PermissionName::AdminOrgSettings);
    }

    public function delete(User $actor, LoanProduct $product): bool
    {
        return $actor->hasPermission(PermissionName::AdminOrgSettings);
    }
}
