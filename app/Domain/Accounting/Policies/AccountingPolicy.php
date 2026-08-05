<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Policies;

use App\Domain\Auth\Enums\PermissionName;
use App\Models\ReserveUtilisation;
use App\Models\User;

/**
 * Authorization for the close and the Reserve fund — Decision Register D1.
 *
 * One policy for the module, in the same spirit as CapitalPolicy: the screens
 * share a small set of grants, and three near-identical classes would only be
 * three places to forget to change something.
 *
 * The Reserve is deliberately split across two roles. D1 makes Admin approval a
 * requirement, so Finance may propose a use and only Admin may release it. A
 * single `reserve.manage` grant would make the approval step decorative.
 */
final class AccountingPolicy
{
    /**
     * Anyone who can read the ledger can read the close. The figures are
     * already visible line by line in the journal; hiding their summary would
     * protect nothing.
     */
    public function viewPeriods(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::LedgerView);
    }

    public function closePeriod(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::AccountingPeriodClose);
    }

    public function viewReserve(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::LedgerView);
    }

    public function requestReserve(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::ReserveRequest);
    }

    /**
     * §14's separation of duties, applied to the Reserve.
     *
     * Holding `reserve.approve` is not enough on its own — the approver must
     * not be the person who raised the request. This is the same rule float
     * transfers, expenses and loan approvals already follow, and it is what
     * makes D1's Admin step a control rather than a formality.
     */
    public function decideReserve(User $actor, ReserveUtilisation $request): bool
    {
        return $actor->hasPermission(PermissionName::ReserveApprove)
            && $request->requested_by !== $actor->getKey();
    }
}
