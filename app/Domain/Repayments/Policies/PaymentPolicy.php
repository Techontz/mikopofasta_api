<?php

declare(strict_types=1);

namespace App\Domain\Repayments\Policies;

use App\Domain\Auth\Enums\PermissionName;
use App\Models\Payment;
use App\Models\User;

/**
 * Authorization for repayments — §14, mirroring the frontend's four grants.
 *
 *   repayments.view       see the payment book
 *   repayments.manage     confirm payments, allocate suspense, run the
 *                         overdue job
 *   repayments.cash_entry record a cash payment (the Teller's ONLY write)
 *   repayments.reconcile  bank reconciliation (Finance alone — §14 assigns it
 *                         to Finance even over Admin)
 *
 * The Teller is the sharpest case: §14 gives them cash entry and nothing else,
 * "no reconciliation, no reversal". Those are three different grants precisely
 * so the person handling the cash is not the person confirming it arrived.
 */
final class PaymentPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::RepaymentsView);
    }

    public function view(User $actor, Payment $payment): bool
    {
        return $actor->hasPermission(PermissionName::RepaymentsView);
    }

    public function recordCash(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::RepaymentsCashEntry);
    }

    public function manage(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::RepaymentsManage);
    }

    public function reconcile(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::RepaymentsReconcile);
    }
}
