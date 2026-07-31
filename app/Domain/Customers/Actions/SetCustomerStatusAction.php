<?php

declare(strict_types=1);

namespace App\Domain\Customers\Actions;

use App\Domain\Customers\Enums\CustomerStatus;
use App\Domain\Customers\Exceptions\CustomerStateException;
use App\Enums\AuditAction;
use App\Models\Customer;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Suspends or reactivates a customer
 * (PATCH /customers/{customer}/status).
 *
 * Deliberately cannot reach `frozen`: a freeze needs a reason and an
 * `account_freezes` row, so it goes through FreezeCustomerAction. Mirrors the
 * frontend's setCustomerActiveStatus, including its refusal to touch a frozen
 * account.
 */
final class SetCustomerStatusAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Customer $customer, bool $active, User $actor): Customer
    {
        if ($customer->isFrozen()) {
            throw CustomerStateException::frozenAccountCannotChangeStatus();
        }

        $target = $active ? CustomerStatus::Active : CustomerStatus::Suspended;

        return DB::transaction(function () use ($customer, $target, $active, $actor): Customer {
            $before = ['status' => $customer->status->value];

            $customer->update(['status' => $target]);

            $this->audit->log(
                $active ? AuditAction::CustomerReactivated : AuditAction::CustomerSuspended,
                $customer,
                before: $before,
                after: ['status' => $target->value],
                actor: $actor,
            );

            return $customer->fresh(['category', 'branch']);
        });
    }
}
