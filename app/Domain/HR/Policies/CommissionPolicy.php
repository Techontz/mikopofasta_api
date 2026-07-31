<?php

declare(strict_types=1);

namespace App\Domain\Hr\Policies;

use App\Domain\Auth\Enums\PermissionName;
use App\Models\CommissionPool;
use App\Models\User;

/**
 * Authorization for commission — §14.
 *
 * Viewing follows `hr.view`, which the frontend's nav also grants to Finance
 * through their own payroll responsibilities.
 *
 * Generating pools is Finance's, not HR's. A pool is derived from branch
 * profit — an accounting figure — and §11 sequences it after month-end close,
 * which is Finance's job. HR consumes the result when payroll runs.
 */
final class CommissionPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::HrView)
            || $actor->hasPermission(PermissionName::PayrollFinalize);
    }

    public function view(User $actor, CommissionPool $pool): bool
    {
        return $this->viewAny($actor);
    }

    public function generate(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::PayrollFinalize);
    }
}
