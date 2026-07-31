<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Policies;

use App\Domain\Auth\Enums\PermissionName;
use App\Models\FloatTransfer;
use App\Models\HqAccountTransfer;
use App\Models\User;

/**
 * Authorization for the whole Capital module.
 *
 * Read behind `treasury.view`, write behind `treasury.manage` — unlike the
 * settings lookups, none of this is open: who holds equity and how much cash
 * sits in which till is not information every teller needs.
 *
 * One policy for the module because all six screens share the same pair of
 * permissions; three near-identical classes would only be three places to
 * forget to change.
 */
final class CapitalPolicy
{
    public function view(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::TreasuryView);
    }

    public function manage(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::TreasuryManage);
    }

    /**
     * §14 separation of duties: raising a transfer and approving it are two
     * different people's jobs, the same rule loan approval follows.
     */
    public function decide(User $actor, FloatTransfer $transfer): bool
    {
        return $this->manage($actor) && $transfer->requested_by !== $actor->getKey();
    }

    /**
     * The same rule for headquarters movements.
     *
     * A separate method rather than a union type on `decide`, because the two
     * are different records and a policy that accepts either invites a caller
     * to pass the wrong one to the wrong screen's check.
     *
     * `requested_by` is null on rows imported from the legacy system, which
     * recorded only a staff name. Those cannot fail the self-approval test, and
     * that is correct — an imported row was raised by someone who is not the
     * current user by definition.
     */
    public function decideHqTransaction(User $actor, HqAccountTransfer $transfer): bool
    {
        return $this->manage($actor) && $transfer->requested_by !== $actor->getKey();
    }
}
