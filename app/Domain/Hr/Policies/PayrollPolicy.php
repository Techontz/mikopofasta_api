<?php

declare(strict_types=1);

namespace App\Domain\Hr\Policies;

use App\Domain\Auth\Enums\PermissionName;
use App\Models\PayrollRun;
use App\Models\User;

/**
 * Authorization for payroll — §14's sharpest separation of duties.
 *
 *   payroll.generate  HR produces the draft
 *   payroll.finalize  Finance posts it, and later pays it
 *
 * "HR can generate payroll but not finalize/pay it (Finance does)." The two
 * are different permissions held by different roles precisely so that the
 * person who computes what everyone is owed is not the person who releases the
 * money. Collapsing them into one grant would remove the only control on the
 * largest recurring payment the company makes.
 *
 * Note that `finalize` and `pay` share a grant. §14 defines "payroll
 * finalization" as one Finance responsibility, and the frontend checks
 * PAYROLL_FINALIZE for both; splitting them here would invent a permission the
 * contract does not have.
 */
final class PayrollPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::HrView)
            || $actor->hasPermission(PermissionName::PayrollFinalize);
    }

    public function view(User $actor, PayrollRun $run): bool
    {
        return $this->viewAny($actor);
    }

    public function generate(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::PayrollGenerate);
    }

    public function finalize(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::PayrollFinalize);
    }

    /**
     * §11: disbursing a staff advance "is never HR's to execute". It is money
     * leaving the company, so it takes the Finance money-movement grant — the
     * same one that releases payroll.
     */
    public function disburseAdvance(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::PayrollFinalize);
    }
}
