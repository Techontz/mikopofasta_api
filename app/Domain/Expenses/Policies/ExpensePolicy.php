<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Policies;

use App\Domain\Auth\Enums\PermissionName;
use App\Models\ExpenseRequest;
use App\Models\User;

/**
 * Authorization for the whole Expenses module.
 *
 * The permission pair is Treasury's, not a new one. The frontend already gates
 * every expense screen on `treasury.view` (config/legacy-nav.ts), and adding an
 * `expenses.*` pair here would mean either a permission the UI never checks or
 * a UI change to chase a backend preference. Expenses are treasury work.
 *
 * `admin.org_settings` additionally opens the category register, because
 * ACCOUNT OVERVIEW §G puts creating categories in the administrator's hands
 * ("Super Admin ata-create categories") — and each one mints a ledger account,
 * which is a chart-of-accounts change however it is spelled on the screen.
 */
final class ExpensePolicy
{
    public function view(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::TreasuryView);
    }

    /** Filing a request. Anyone who can work the treasury screens may spend. */
    public function request(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::TreasuryView);
    }

    /** Creating, renaming or retiring a category. */
    public function manageCategories(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::TreasuryManage)
            || $actor->hasPermission(PermissionName::AdminOrgSettings);
    }

    /**
     * Approving or rejecting — §14 separation of duties.
     *
     * Approval releases money and posts it, so the person who asked for it may
     * not be the person who grants it. This is the rule loan approval and
     * branch-to-branch float already follow, and an expense is no different for
     * being small.
     */
    public function decide(User $actor, ExpenseRequest $request): bool
    {
        return $actor->hasPermission(PermissionName::TreasuryManage)
            && $request->requested_by !== $actor->getKey();
    }

    /**
     * Adding the decision comment.
     *
     * Gated on `treasury.manage` but with no self-check: a comment records why
     * a decision went the way it did, and the requester annotating their own
     * pending row moves no money.
     */
    public function comment(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::TreasuryManage);
    }

    /**
     * Withdrawing a request.
     *
     * Only while pending, and only by the person who raised it or someone who
     * can decide it. An approved request has posted to the ledger and §2's
     * no-delete rule takes over from there — it is reversed, not removed.
     */
    public function delete(User $actor, ExpenseRequest $request): bool
    {
        if (! $request->status->isDecidable()) {
            return false;
        }

        return $request->requested_by === $actor->getKey()
            || $actor->hasPermission(PermissionName::TreasuryManage);
    }
}
