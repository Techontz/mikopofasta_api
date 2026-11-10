<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\CustomerBranchTransfer;
use App\Models\CustomerUpdateRequest;
use App\Models\Employee;
use App\Notifications\CustomerActivity;
use App\Services\AccessControl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Header-bell notifications for activity on customers.
 *
 * - Work waiting for a decision (a registration awaiting KYC approval, a change to a registered customer's details, a
 *   branch transfer) goes to every active employee who may decide it and sees the customer's branch, and always to CRM.
 * - The decision goes back to the employee who asked for it.
 * - Nobody is notified about their own action, and a failure here never blocks the action itself.
 */
class CustomerActivityNotifier
{
    public const CRM_ROLE = 'crm';

    public function __construct(private AccessControl $access) {}

    /**
     * Called for every customer audit entry ({@see CustomerRegistrar::audit()}).
     *
     * @param  array<string, mixed>  $after
     */
    public function afterAudit(Customer $customer, string $action, array $after, ?Employee $actor): void
    {
        rescue(fn () => $this->dispatch($customer, $action, $after, $actor));
    }

    /**
     * A registered customer's KYC has just become complete.
     */
    public function kycCompleted(Customer $customer, ?Employee $actor): void
    {
        rescue(function () use ($customer, $actor): void {
            $waiting = $customer->approval_status === 'pending';
            $this->toReviewers($customer, 'customers.approve', $actor, 'kyc_completed', 'Customer KYC completed',
                $this->name($customer).' now has every KYC requirement'.($waiting ? ' and awaits approval.' : '.'),
                $waiting ? '/customers/approvals' : "/customers/{$customer->id}");
        });
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function dispatch(Customer $customer, string $action, array $after, ?Employee $actor): void
    {
        $name = $this->name($customer);
        $profile = "/customers/{$customer->id}";

        match ($action) {
            'Customer.registered' => $this->toReviewers($customer, 'customers.approve', $actor, 'registration', 'New customer awaiting KYC approval',
                "{$name} was registered by {$this->actorName($actor)} and awaits KYC approval.", '/customers/approvals'),
            'Customer.resubmitted' => $this->toReviewers($customer, 'customers.approve', $actor, 'registration', 'Customer resubmitted for KYC approval',
                "{$name} was corrected and resubmitted by {$this->actorName($actor)}.", '/customers/approvals'),
            'Customer.approved' => $this->toEmployee($customer->employee_id ?? $customer->created_by, $actor, $customer, 'registration_decision', 'Customer registration approved',
                "{$name} was approved by {$this->actorName($actor)}.", $profile),
            'Customer.rejected' => $this->toEmployee($customer->employee_id ?? $customer->created_by, $actor, $customer, 'registration_decision', 'Customer registration returned',
                "{$name} was returned by {$this->actorName($actor)}: ".($after['reason'] ?? 'no reason given').'.', $profile),
            'Customer.update_requested' => $this->toReviewers($customer, 'customers.approve_updates', $actor, 'update', 'Customer update awaiting approval',
                "{$this->actorName($actor)} changed ".implode(', ', (array) ($after['fields'] ?? [])).' for '.$name.'.', '/crm/updates'),
            'Customer.update_approved', 'Customer.update_rejected' => $this->toEmployee(
                CustomerUpdateRequest::whereKey($after['request_id'] ?? null)->value('requested_by'), $actor, $customer, 'update_decision',
                $action === 'Customer.update_approved' ? 'Customer update approved' : 'Customer update rejected',
                "Your change to {$name} was ".($action === 'Customer.update_approved' ? 'approved' : 'rejected')." by {$this->actorName($actor)}.", $profile),
            'Customer.branch_transfer_requested' => $this->toReviewers($customer, 'customers.approve_transfer', $actor, 'transfer', 'Branch transfer awaiting approval',
                "{$this->actorName($actor)} asked to move {$name} to another branch.", '/customers/branch-transfers'),
            'Customer.branch_transferred', 'Customer.branch_transfer_rejected' => $this->toEmployee(
                CustomerBranchTransfer::whereKey($after['transfer_id'] ?? null)->value('requested_by'), $actor, $customer, 'transfer_decision',
                $action === 'Customer.branch_transferred' ? 'Branch transfer approved' : 'Branch transfer rejected',
                "The branch transfer of {$name} was ".($action === 'Customer.branch_transferred' ? 'approved' : 'rejected')." by {$this->actorName($actor)}.", $profile),
            'Customer.deleted' => $this->send($this->crm($customer, $actor), $customer, 'deleted', 'Customer deleted',
                "{$name} was deleted by {$this->actorName($actor)}.", $profile),
            default => null,
        };
    }

    private function toReviewers(Customer $customer, string $permission, ?Employee $actor, string $kind, string $title, string $message, string $link): void
    {
        $reviewers = $this->colleagues($customer, $actor)
            ->filter(fn (Employee $employee): bool => $employee->role?->key === self::CRM_ROLE || $this->access->can($employee, $permission));

        $this->send($reviewers, $customer, $kind, $title, $message, $link);
    }

    private function toEmployee(?int $employeeId, ?Employee $actor, Customer $customer, string $kind, string $title, string $message, string $link): void
    {
        if ($employeeId === null || $employeeId === $actor?->getKey()) {
            return;
        }

        $this->send(Employee::whereKey($employeeId)->where('company_id', $customer->company_id)->where('status', 'active')->get(), $customer, $kind, $title, $message, $link);
    }

    /**
     * @return Collection<int, Employee>
     */
    private function crm(Customer $customer, ?Employee $actor): Collection
    {
        return $this->colleagues($customer, $actor)->filter(fn (Employee $employee): bool => $employee->role?->key === self::CRM_ROLE);
    }

    /**
     * Active staff of the customer's company (not the actor, not shareholder logins) whose scope includes the customer's branch.
     *
     * @return Collection<int, Employee>
     */
    private function colleagues(Customer $customer, ?Employee $actor): Collection
    {
        return Employee::query()
            ->where('company_id', $customer->company_id)
            ->where('status', 'active')
            ->when($actor !== null, fn ($query) => $query->whereKeyNot($actor->getKey()))
            ->with(['role.permissions', 'permissionOverrides', 'shareHolder'])
            ->get()
            ->reject(fn (Employee $employee): bool => $employee->isShareholderAccount())
            ->filter(function (Employee $employee) use ($customer): bool {
                $branches = $this->access->branchIds($employee);

                return $branches === null || in_array((int) $customer->branch_id, $branches, true);
            });
    }

    /**
     * @param  Collection<int, Employee>  $recipients
     */
    private function send(Collection $recipients, Customer $customer, string $kind, string $title, string $message, string $link): void
    {
        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new CustomerActivity([
            'kind' => $kind,
            'title' => $title,
            'message' => $message,
            'link' => $link,
            'customer_id' => $customer->id,
        ]));
    }

    private function name(Customer $customer): string
    {
        return trim($customer->full_name.' ('.$customer->customer_number.')');
    }

    private function actorName(?Employee $actor): string
    {
        return $actor?->full_name ?? 'the system';
    }
}
