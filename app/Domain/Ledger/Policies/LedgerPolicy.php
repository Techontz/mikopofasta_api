<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Policies;

use App\Domain\Auth\Enums\PermissionName;
use App\Models\JournalEntry;
use App\Models\User;

/**
 * Authorization for the ledger — §14.
 *
 * `ledger.reverse.request` and `ledger.reverse.approve` are deliberately
 * separate grants held by different roles: a Branch Manager can ask for a
 * reversal, only Finance or Super Admin can grant one. That split is the
 * accounting control; collapsing it would let one person both move money and
 * bless the movement.
 *
 * There is no `create` ability. Entries are never posted through an endpoint —
 * they are a consequence of a business event, and LedgerService is the only
 * writer (§5).
 */
final class LedgerPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::LedgerView);
    }

    public function view(User $actor, JournalEntry $entry): bool
    {
        return $actor->hasPermission(PermissionName::LedgerView);
    }

    public function requestReversal(User $actor, JournalEntry $entry): bool
    {
        return $actor->hasPermission(PermissionName::LedgerReverseRequest);
    }

    public function approveReversal(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::LedgerReverseApprove);
    }
}
