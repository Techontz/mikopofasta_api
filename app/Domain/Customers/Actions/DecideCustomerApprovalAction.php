<?php

declare(strict_types=1);

namespace App\Domain\Customers\Actions;

use App\Domain\Customers\Enums\CustomerApprovalStatus;
use App\Domain\Customers\Exceptions\CustomerStateException;
use App\Enums\AuditAction;
use App\Models\Customer;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Approves or rejects a customer whose category requires extra approval
 * (POST /customers/{customer}/approve | /reject).
 *
 * Both directions stamp `approved_by`/`approved_at` — the column records who
 * decided and when, not merely who said yes. Rejection additionally keeps the
 * reason, which the profile shows.
 */
final class DecideCustomerApprovalAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function approve(Customer $customer, User $actor): Customer
    {
        return $this->decide($customer, CustomerApprovalStatus::Approved, null, $actor);
    }

    public function reject(Customer $customer, string $reason, User $actor): Customer
    {
        return $this->decide($customer, CustomerApprovalStatus::Rejected, $reason, $actor);
    }

    private function decide(
        Customer $customer,
        CustomerApprovalStatus $decision,
        ?string $reason,
        User $actor,
    ): Customer {
        // Only a pending registration can be decided — re-approving an
        // already-approved customer would rewrite the original decision's
        // author and timestamp.
        if (! $customer->approval_status->isPending()) {
            throw CustomerStateException::notAwaitingApproval();
        }

        return DB::transaction(function () use ($customer, $decision, $reason, $actor): Customer {
            $before = ['approval_status' => $customer->approval_status->value];

            $customer->update([
                'approval_status' => $decision,
                'approved_by' => $actor->getKey(),
                'approved_at' => Date::now(),
                'rejection_reason' => $reason,
            ]);

            $this->audit->log(
                $decision === CustomerApprovalStatus::Approved
                    ? AuditAction::CustomerApproved
                    : AuditAction::CustomerRejected,
                $customer,
                before: $before,
                after: ['approval_status' => $decision->value, 'rejection_reason' => $reason],
                actor: $actor,
            );

            return $customer->fresh(['category', 'branch']);
        });
    }
}
