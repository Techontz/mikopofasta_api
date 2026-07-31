<?php

declare(strict_types=1);

namespace App\Domain\Customers\Actions;

use App\Domain\Customers\Enums\CustomerStatus;
use App\Domain\Customers\Exceptions\CustomerStateException;
use App\Enums\AuditAction;
use App\Enums\FreezableType;
use App\Models\AccountFreeze;
use App\Models\Customer;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Freezes and unfreezes a customer account (spec §2.1, §9).
 *
 * A freeze is two facts kept in step inside one transaction: the customer's
 * status, and an `account_freezes` row recording who froze it, why, and when.
 * Storing only the status would lose the reason; storing only the row would
 * leave the loan engine unable to see the block cheaply.
 *
 * §9: a frozen customer is blocked from NEW loans; existing loans continue
 * through their own state machine untouched.
 */
final class FreezeCustomerAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function freeze(Customer $customer, string $reason, User $actor): Customer
    {
        if ($customer->isFrozen()) {
            throw CustomerStateException::alreadyFrozen();
        }

        return DB::transaction(function () use ($customer, $reason, $actor): Customer {
            $before = ['status' => $customer->status->value];

            $customer->update(['status' => CustomerStatus::Frozen]);

            AccountFreeze::query()->create([
                'freezable_type' => FreezableType::Customer,
                'freezable_id' => $customer->getKey(),
                'reason' => $reason,
                'frozen_by' => $actor->getKey(),
                'frozen_at' => Date::now(),
            ]);

            $this->audit->log(
                AuditAction::CustomerFrozen,
                $customer,
                before: $before,
                after: ['status' => CustomerStatus::Frozen->value, 'reason' => $reason],
                actor: $actor,
            );

            return $customer->fresh(['category', 'branch']);
        });
    }

    public function unfreeze(Customer $customer, User $actor): Customer
    {
        if (! $customer->isFrozen()) {
            throw CustomerStateException::notFrozen();
        }

        return DB::transaction(function () use ($customer, $actor): Customer {
            $customer->update(['status' => CustomerStatus::Active]);

            /*
             * Close the open freeze rather than deleting it — the history of
             * why an account was frozen is exactly what an auditor asks for.
             */
            $open = $customer->openFreeze();
            $open?->update([
                'unfrozen_by' => $actor->getKey(),
                'unfrozen_at' => Date::now(),
            ]);

            $this->audit->log(
                AuditAction::CustomerUnfrozen,
                $customer,
                before: ['status' => CustomerStatus::Frozen->value],
                after: ['status' => CustomerStatus::Active->value],
                actor: $actor,
            );

            return $customer->fresh(['category', 'branch']);
        });
    }
}
