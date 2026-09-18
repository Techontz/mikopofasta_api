<?php

declare(strict_types=1);

namespace App\Domain\CustomerAdvances\Policies;

use App\Domain\Auth\Enums\PermissionName;
use App\Models\CustomerAdvance;
use App\Models\User;

/**
 * Authorization for customer salary advances.
 *
 * No new permission strings. A salary advance is the company lending its own
 * operational money to a customer and collecting it with interest, which is
 * what the loan grants already describe — so the same people who may raise,
 * approve, disburse and collect a loan may do it for an advance, and nobody
 * acquires a new power by this module existing.
 *
 *   loans.view            see the register
 *   loans.create          raise a request
 *   loans.approve         approve or reject it
 *   loans.disburse        move the money
 *   repayments.cash_entry take a collection (the Teller's only write, §14)
 *
 * Approving and disbursing stay separate grants, which is the control that
 * matters here: whoever says the advance is warranted must not also be the
 * person who pays it out.
 */
final class CustomerAdvancePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::LoansView);
    }

    public function view(User $actor, CustomerAdvance $advance): bool
    {
        return $actor->hasPermission(PermissionName::LoansView);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::LoansCreate);
    }

    public function decide(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::LoansApprove);
    }

    public function disburse(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::LoansDisburse);
    }

    /**
     * Taking a payment.
     *
     * `repayments.cash_entry` OR `repayments.manage`: the teller who takes the
     * notes has the first, and Finance recording a bank or mobile-money
     * collection has the second. Requiring cash entry alone would leave a
     * transfer with nobody able to record it.
     */
    public function collect(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::RepaymentsCashEntry)
            || $actor->hasPermission(PermissionName::RepaymentsManage);
    }
}
