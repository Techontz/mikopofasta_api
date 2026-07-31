<?php

declare(strict_types=1);

namespace App\Domain\Organization\Policies;

use App\Domain\Auth\Enums\PermissionName;
use App\Models\CompanyProfile;
use App\Models\User;

/**
 * Authorization for the company profile.
 *
 * The frontend renders this screen read-only for anyone without
 * `admin.org_settings` (`canEdit` in app/(dashboard)/admin/organization/page.tsx)
 * rather than hiding it, so viewing stays open and only the update is gated.
 */
final class CompanyProfilePolicy
{
    public function view(User $actor, CompanyProfile $profile): bool
    {
        return true;
    }

    public function update(User $actor, CompanyProfile $profile): bool
    {
        return $actor->hasPermission(PermissionName::AdminOrgSettings);
    }
}
