<?php

declare(strict_types=1);

namespace App\Domain\Reversals\Policies;

use App\Domain\Auth\Enums\PermissionName;
use App\Models\ReversalRequest;
use App\Models\User;

/**
 * Who may ask for a reversal, and who may grant one — §14.
 *
 * The two grants are deliberately separate and are held by different sets of
 * roles:
 *
 *   ledger.reverse.request   Finance, Admin, Accountant, Super Admin
 *   ledger.reverse.approve   Finance, Admin, Super Admin
 *
 * Finance holding both is not a collapse of the control. DecideReversalAction
 * refuses self-approval outright, so a Finance officer's request is decided by
 * a SECOND Finance officer, by Admin, or by Super Admin — never by themselves.
 * That is the arrangement the client asked for: raised in Finance, approved by
 * somebody else in Finance or above it.
 *
 * The Accountant can raise one and cannot grant one, which is the point of
 * their role: they find the error, somebody with the money grant decides what
 * to do about it.
 */
final class ReversalPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::LedgerReverseRequest)
            || $actor->hasPermission(PermissionName::LedgerReverseApprove);
    }

    public function view(User $actor, ReversalRequest $request): bool
    {
        return $this->viewAny($actor);
    }

    public function request(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::LedgerReverseRequest);
    }

    public function approve(User $actor, ReversalRequest $request): bool
    {
        return $actor->hasPermission(PermissionName::LedgerReverseApprove);
    }

    /**
     * Rejecting is either a decision or a withdrawal. An approver may refuse
     * anyone's request; a requester may always take back their own, which
     * needs no grant because nothing moves.
     */
    public function reject(User $actor, ReversalRequest $request): bool
    {
        return $actor->hasPermission(PermissionName::LedgerReverseApprove)
            || $request->requested_by === $actor->getKey();
    }
}
