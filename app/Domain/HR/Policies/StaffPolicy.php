<?php

declare(strict_types=1);

namespace App\Domain\Hr\Policies;

use App\Domain\Auth\Enums\PermissionName;
use App\Models\StaffProfile;
use App\Models\User;

/**
 * Authorization for staff records — §14.
 *
 *   hr.view    see the staff book, payroll and commission
 *   hr.manage  register and amend staff, raise and decide advances
 *
 * §14 gives HR "Staff registration, payroll generation (not finalization),
 * performance records" across all branches. HR is an HQ function: an
 * organisation does not keep a separate personnel office per branch, and the
 * grants are not branch-scoped for that reason.
 */
final class StaffPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::HrView);
    }

    public function view(User $actor, StaffProfile $staff): bool
    {
        return $actor->hasPermission(PermissionName::HrView);
    }

    public function manage(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::HrManage);
    }

    /**
     * Recording a review is a manager's act, not only HR's — §14 lists
     * performance records under HR but §11 has "Manager records
     * targets/achieved/rating", and a Branch Manager reviews their own staff.
     */
    public function recordPerformance(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::HrManage)
            || $actor->hasPermission(PermissionName::LoansApprove);
    }
}
