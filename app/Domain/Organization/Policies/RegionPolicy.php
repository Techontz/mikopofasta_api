<?php

declare(strict_types=1);

namespace App\Domain\Organization\Policies;

use App\Domain\Auth\Enums\PermissionName;
use App\Models\Region;
use App\Models\User;

/**
 * Authorization for regions.
 *
 * Reads are open to every authenticated user because the customer
 * registration wizard's address step needs the region → district → ward →
 * street chain, and a Loan Officer holds no admin permission at all.
 */
final class RegionPolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function view(User $actor, Region $region): bool
    {
        return true;
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::AdminOrgSettings);
    }

    public function update(User $actor, Region $region): bool
    {
        return $actor->hasPermission(PermissionName::AdminOrgSettings);
    }

    public function delete(User $actor, Region $region): bool
    {
        return $actor->hasPermission(PermissionName::AdminOrgSettings);
    }
}
