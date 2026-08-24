<?php

declare(strict_types=1);

namespace App\Domain\Customers\Actions;

use App\Domain\Customers\Enums\CustomerApprovalStatus;
use App\Domain\Customers\Exceptions\CustomerStateException;
use App\Domain\Customers\Services\KycEvaluator;
use App\Enums\AuditAction;
use App\Models\Customer;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * The MANAGER APPROVAL stage of registration —
 * POST /customers/{customer}/approve | /reject | /resubmit.
 *
 * This is the customer-registration gate, and it is a different thing from the
 * loan approval chain. A Branch Manager decides whether a registration is
 * sound; the Loan Officer → Branch Manager → Zone/Credit → Finance routing
 * decides whether a LOAN is sound, and neither borrows the other's
 * permissions. `customers.approve` gates this; `loans.approve` and its
 * siblings gate that.
 *
 * Three rules govern a decision here, and each closes a hole that existed
 * while approval was optional:
 *
 *   1. THE FILE MUST BE FINISHED. Approving an incomplete registration would
 *      put a manager's name against something nobody could assess — and would
 *      make a customer loan-eligible with, say, no face scan on record.
 *
 *   2. YOU MAY NOT APPROVE YOUR OWN. The same separation of duties the loan
 *      chain enforces at every stage. A single person must not be able to put
 *      a borrower on the book unseen.
 *
 *   3. A DECISION IS MADE ONCE. Re-approving would rewrite the original
 *      decision's author and timestamp.
 *
 * Both directions stamp `approved_by`/`approved_at` — the column records who
 * decided and when, not merely who said yes. A rejection keeps its reason,
 * which the profile shows and which the officer needs in order to fix the file.
 */
final class DecideCustomerApprovalAction
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly KycEvaluator $kyc,
    ) {}

    public function approve(Customer $customer, User $actor): Customer
    {
        /*
         * Checked here rather than only in the UI. "Approval should only be
         * enabled when all mandatory requirements are complete" is a rule
         * about the record, not about a button — a disabled button is a
         * courtesy, and this is the enforcement.
         */
        $outstanding = $this->kyc->outstanding($customer);

        if ($outstanding !== []) {
            throw CustomerStateException::kycIncompleteForApproval(implode(' ', $outstanding));
        }

        return $this->decide($customer, CustomerApprovalStatus::Approved, null, $actor);
    }

    /**
     * Reject, or return for correction — the same transition.
     *
     * The customer enum has no separate "returned" case and does not need one:
     * what the officer must be able to do is read why, fix the record, and send
     * it back, and `resubmit()` below is that. Adding a fourth state would have
     * meant two ways of expressing one situation, which is how a status column
     * stops being trustworthy.
     */
    public function reject(Customer $customer, string $reason, User $actor): Customer
    {
        return $this->decide($customer, CustomerApprovalStatus::Rejected, $reason, $actor);
    }

    /**
     * Put a returned registration back in front of the manager.
     *
     * Without this a rejection is terminal, and a customer rejected over a
     * mistyped ward could never be registered at all — their phone and
     * National ID are already taken by the record that was refused. Gated on
     * `customers.manage` at the controller, because this is the originating
     * officer's action, not an approver's.
     *
     * The rejection reason is cleared: it described a file that has since been
     * corrected, and leaving it on the record would have the profile still
     * explaining a refusal that no longer applies. The audit trail keeps it.
     */
    public function resubmit(Customer $customer, User $actor): Customer
    {
        if ($customer->approval_status !== CustomerApprovalStatus::Rejected) {
            throw CustomerStateException::notReturned();
        }

        return DB::transaction(function () use ($customer, $actor): Customer {
            $before = [
                'approval_status' => $customer->approval_status->value,
                'rejection_reason' => $customer->rejection_reason,
            ];

            $customer->update([
                'approval_status' => CustomerApprovalStatus::Pending,
                'approved_by' => null,
                'approved_at' => null,
                'rejection_reason' => null,
            ]);

            $this->audit->log(
                AuditAction::CustomerRegistrationResubmitted,
                $customer,
                before: $before,
                after: ['approval_status' => CustomerApprovalStatus::Pending->value],
                actor: $actor,
            );

            return $customer->fresh(['category', 'branch']);
        });
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

        /*
         * Separation of duties, mirroring LoanApprovalWorkflow's own guard.
         * The permission check upstream says this person MAY approve
         * registrations; this says they may not approve THIS one, because they
         * are the one who created it.
         */
        if ($customer->created_by !== null && $customer->created_by === $actor->getKey()) {
            throw CustomerStateException::selfApproval();
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
