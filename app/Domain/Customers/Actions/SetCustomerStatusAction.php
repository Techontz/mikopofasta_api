<?php

declare(strict_types=1);

namespace App\Domain\Customers\Actions;

use App\Domain\Customers\Enums\CustomerStatus;
use App\Domain\Customers\Exceptions\CustomerStateException;
use App\Enums\AuditAction;
use App\Models\Customer;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Suspends or reactivates a customer
 * (PATCH /customers/{customer}/status).
 *
 * Deliberately cannot reach `frozen`: a freeze needs a reason and an
 * `account_freezes` row, so it goes through FreezeCustomerAction. Mirrors the
 * frontend's setCustomerActiveStatus, including its refusal to touch a frozen
 * account.
 *
 * The reason, remarks and the operator are persisted on the customer so the
 * profile can say why the account stands as it does; the audit entry carries
 * the whole context — who, when, from which branch, at which address, on which
 * client — so the history survives the record being changed again.
 */
final class SetCustomerStatusAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param array{reason: string, remarks?: string|null} $justification
     */
    public function handle(Customer $customer, bool $active, array $justification, User $actor): Customer
    {
        if ($customer->isFrozen()) {
            throw CustomerStateException::frozenAccountCannotChangeStatus();
        }

        $target = $active ? CustomerStatus::Active : CustomerStatus::Suspended;

        $reason = trim($justification['reason']);
        $remarks = isset($justification['remarks']) ? trim((string) $justification['remarks']) : null;

        return DB::transaction(function () use ($customer, $target, $active, $reason, $remarks, $actor): Customer {
            $before = [
                'status' => $customer->status->value,
                'status_reason' => $customer->status_reason,
            ];

            $changedAt = Date::now();

            $customer->update([
                'status' => $target,
                'status_reason' => $reason,
                'status_remarks' => $remarks === '' ? null : $remarks,
                'status_changed_at' => $changedAt,
                'status_changed_by' => $actor->getKey(),
            ]);

            /*
             * Branch is named as well as numbered. An audit row read years
             * later should not depend on a branch table lookup that may have
             * been renamed or merged since.
             */
            $customer->loadMissing('branch');

            $this->audit->log(
                $active ? AuditAction::CustomerReactivated : AuditAction::CustomerSuspended,
                $customer,
                before: $before,
                after: [
                    'status' => $target->value,
                    'reason' => $reason,
                    'remarks' => $remarks === '' ? null : $remarks,
                    'operator' => $actor->name,
                    'operator_id' => $actor->getKey(),
                    'branch_id' => $customer->branch_id,
                    'branch' => $customer->branch?->name,
                    'changed_at' => $changedAt->toIso8601String(),
                ],
                actor: $actor,
            );

            return $customer->fresh(['category', 'branch']);
        });
    }
}
