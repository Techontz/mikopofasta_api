<?php

declare(strict_types=1);

namespace App\Domain\Organization\Policies;

use App\Domain\Auth\Enums\PermissionName;
use App\Models\User;
use App\Models\Zone;

/**
 * Authorization for zones. Read-open, write behind `admin.org_settings` —
 * the same split as branches, and for the same reason: the zone list is a
 * lookup the branch form and the commission screens both need.
 */
final class ZonePolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function view(User $actor, Zone $zone): bool
    {
        return true;
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::AdminOrgSettings);
    }

    public function update(User $actor, Zone $zone): bool
    {
        return $actor->hasPermission(PermissionName::AdminOrgSettings);
    }

    public function delete(User $actor, Zone $zone): bool
    {
        return $actor->hasPermission(PermissionName::AdminOrgSettings);
    }
}
