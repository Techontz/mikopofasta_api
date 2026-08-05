<?php

declare(strict_types=1);

namespace App\Domain\Loans\Policies;

use App\Domain\Auth\Enums\PermissionName;
use App\Models\Loan;
use App\Models\User;

/**
 * Authorization for loans — §14's separation of duties expressed as distinct
 * grants, each held by a different role:
 *
 *   loans.view          read the book
 *   loans.create        raise an application (Loan Officer, Branch Manager)
 *   loans.approve       manager decision (Branch Manager, Admin) — never the
 *                       officer who raised it, enforced in the action
 *   loans.credit_review telco verification (Credit Officer)
 *   loans.disburse      prepare and retry disbursement (Finance)
 *
 * Branch scope (§13) is enforced by BranchScopeGuard, not here: a scope
 * failure must surface as BRANCH_SCOPE_VIOLATION and be audited, which a
 * yes/no policy cannot do.
 */
final class LoanPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::LoansView);
    }

    public function view(User $actor, Loan $loan): bool
    {
        return $actor->hasPermission(PermissionName::LoansView);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::LoansCreate);
    }

    public function decideApproval(User $actor, Loan $loan): bool
    {
        return $actor->hasPermission(PermissionName::LoansApprove);
    }

    /**
     * The mandate OTP is captured by whoever is walking the customer through
     * the application, so it rides on loans.create rather than a grant of its
     * own — matching the frontend, which gates verifyMandateOtp on
     * LOANS_CREATE.
     */
    public function verifyMandate(User $actor, Loan $loan): bool
    {
        return $actor->hasPermission(PermissionName::LoansCreate);
    }

    public function creditReview(User $actor, Loan $loan): bool
    {
        return $actor->hasPermission(PermissionName::LoansCreditReview);
    }

    public function disburse(User $actor, Loan $loan): bool
    {
        return $actor->hasPermission(PermissionName::LoansDisburse);
    }

    /**
     * Closing a loan settles the book and starts the customer's cooldown, so
     * it sits with the money role rather than with origination.
     */
    public function close(User $actor, Loan $loan): bool
    {
        return $actor->hasPermission(PermissionName::LoansDisburse);
    }

    /**
     * Closing a loan early forgives interest that was contractually owed, so it
     * carries its own grant — the roles that originate a loan must not also be
     * able to discount it.
     */
    public function settleEarly(User $actor, Loan $loan): bool
    {
        return $actor->hasPermission(PermissionName::LoansSettleEarly);
    }

    public function cancel(User $actor, Loan $loan): bool
    {
        return $actor->hasPermission(PermissionName::LoansApprove);
    }

    /**
     * Writing a loan off is the only operation here that reduces what a
     * borrower owes without anyone paying, so it carries its own grant rather
     * than riding on `loans.approve` — the role that originates a loan must not
     * be the role that can forgive it.
     */
    public function writeOff(User $actor, Loan $loan): bool
    {
        return $actor->hasPermission(PermissionName::LoansWriteOff);
    }

    public function recover(User $actor, Loan $loan): bool
    {
        return $actor->hasPermission(PermissionName::LoansRecover);
    }
}
