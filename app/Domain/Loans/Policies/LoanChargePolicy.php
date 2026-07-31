<?php

declare(strict_types=1);

namespace App\Domain\Loans\Policies;

use App\Domain\Auth\Enums\PermissionName;
use App\Models\User;

/**
 * Authorization for the three Loan Charges settings.
 *
 * Read-open, write behind `admin.org_settings` — the same split BranchPolicy
 * and ZonePolicy use, and for the same reason: these are lookups the loan
 * product screens read, while changing them is an administrative act.
 *
 * One policy for all three because they share a single permission and are
 * presented as one module; splitting it into three identical classes would
 * only create three places to forget to change.
 */
final class LoanChargePolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function manage(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::AdminOrgSettings);
    }
}
