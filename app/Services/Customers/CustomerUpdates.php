<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\CustomerUpdateRequest;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Changes to a registered customer's details (user ruling 2026-10-15, CRM department):
 *
 *  - branch staff (customers.edit / customers.manage without customers.approve_updates) change a REGISTERED customer (approved,
 *    or registered where no approval was required) → the
 *    change is held as a pending {@see CustomerUpdateRequest} with each field's old and new value, and nothing on the customer
 *    changes until CRM approves it; one change per customer waits at a time;
 *  - CRM and Admin (customers.approve_updates) change the customer at once — recorded as an approved request, so the Customer
 *    Updates list shows every change, whoever made it;
 *  - a registration still waiting for KYC approval, or returned, is edited directly: KYC approval reviews it as a whole;
 *  - approving re-checks that the fields still hold the old values recorded, so a change approved later never silently
 *    overwrites a newer one.
 */
class CustomerUpdates
{
    /** Registrations already accepted: changes to them are reviewed field by field. */
    private const REGISTERED = ['approved', 'not_required'];

    /** List fields compared by their names only (the rows themselves are replaced when the change is applied). */
    private const LIST_FIELDS = ['nextOfKin', 'guarantors'];

    public function __construct(private readonly CustomerRegistrar $registrar) {}

    /**
     * Whether a change by this employee waits for CRM approval.
     */
    public function needsApproval(Customer $customer, Employee $actor): bool
    {
        return $this->isRegistered($customer) && ! Gate::forUser($actor)->allows('customers.approve_updates');
    }

    /**
     * Apply or hold a change from the customer form. Returns the request when the change waits for approval, null when applied.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException
     */
    public function submit(Customer $customer, array $payload, Employee $actor): ?CustomerUpdateRequest
    {
        $changes = $this->changes($customer, $payload);

        if (! $this->needsApproval($customer, $actor)) {
            DB::transaction(function () use ($customer, $payload, $actor, $changes): void {
                $this->registrar->update($customer, $payload, $actor);
                if ($changes !== [] && $this->isRegistered($customer)) {
                    $this->record($customer, $payload, $changes, $actor, CustomerUpdateRequest::STATUS_APPROVED, $actor);
                }
            });

            return null;
        }

        if ($changes === []) {
            throw ValidationException::withMessages(['customer' => 'Nothing was changed.']);
        }
        if (CustomerUpdateRequest::where('customer_id', $customer->id)->where('status', CustomerUpdateRequest::STATUS_PENDING)->exists()) {
            throw ValidationException::withMessages(['customer' => 'A change to this customer is already waiting for CRM approval.']);
        }

        $request = $this->record($customer, $payload, $changes, $actor, CustomerUpdateRequest::STATUS_PENDING);
        $this->registrar->audit($customer, 'Customer.update_requested', ['fields' => array_column($changes, 'field')], [], $actor);

        return $request;
    }

    /**
     * @throws ValidationException
     */
    public function approve(CustomerUpdateRequest $request, Employee $approver): void
    {
        $this->assertPending($request);

        DB::transaction(function () use ($request, $approver): void {
            $customer = $request->customer()->lockForUpdate()->firstOrFail();
            $current = $this->values($customer);
            foreach ($request->changes as $change) {
                if (($current[$change['key']] ?? '') !== $change['old']) {
                    throw ValidationException::withMessages(['customer' => "{$change['field']} has changed since this request was made. Reject it and ask the branch to submit the change again."]);
                }
            }

            $this->registrar->update($customer, $request->payload, $approver);
            $request->update(['status' => CustomerUpdateRequest::STATUS_APPROVED, 'decided_by' => $approver->id, 'decided_at' => now()]);
            $this->registrar->audit($customer, 'Customer.update_approved', ['request_id' => $request->id], [], $approver);
        });
    }

    /**
     * @throws ValidationException
     */
    public function reject(CustomerUpdateRequest $request, Employee $approver, string $reason): void
    {
        $this->assertPending($request);

        $request->update(['status' => CustomerUpdateRequest::STATUS_REJECTED, 'decided_by' => $approver->id, 'decided_at' => now(), 'rejection_reason' => $reason]);
        $this->registrar->audit($request->customer, 'Customer.update_rejected', ['request_id' => $request->id, 'reason' => $reason], [], $approver);
    }

    /**
     * The fields a payload changes, with their current and new values as shown to CRM.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{key: string, field: string, old: string, new: string}>
     */
    public function changes(Customer $customer, array $payload): array
    {
        $before = $this->values($customer);
        $after = $this->values($customer, $payload);

        $changes = [];
        foreach ($after as $key => $value) {
            // Only customer details: request metadata in the payload (e.g. the device) is not a change to review.
            if (array_key_exists($key, $before) && $before[$key] !== $value) {
                $changes[] = ['key' => $key, 'field' => Str::headline($key), 'old' => $before[$key], 'new' => $value];
            }
        }

        return $changes;
    }

    /**
     * The customer's details as flat text values (bank details split into their fields, lists as their names), optionally with
     * a payload applied.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function values(Customer $customer, array $payload = []): array
    {
        $values = [];
        foreach ($this->registrar->payloadFor($customer, $payload) as $key => $value) {
            if ($key === 'bankDetails') {
                foreach (['bankName', 'accountNumber', 'accountName'] as $bankKey) {
                    $values[$bankKey] = trim((string) (is_array($value) ? ($value[$bankKey] ?? '') : ''));
                }
            } elseif (in_array($key, self::LIST_FIELDS, true)) {
                $values[$key] = collect(is_array($value) ? $value : [])->pluck('name')->filter()->implode(', ');
            } elseif (is_array($value)) {
                $values[$key] = $value === [] ? '' : (string) json_encode($value);
            } else {
                $values[$key] = trim((string) ($value ?? ''));
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array{key: string, field: string, old: string, new: string}>  $changes
     */
    private function record(Customer $customer, array $payload, array $changes, Employee $requester, string $status, ?Employee $decider = null): CustomerUpdateRequest
    {
        return CustomerUpdateRequest::create([
            'company_id' => $customer->company_id,
            'branch_id' => $customer->branch_id,
            'customer_id' => $customer->id,
            'payload' => $payload,
            'changes' => $changes,
            'status' => $status,
            'requested_by' => $requester->id,
            'decided_by' => $decider?->id,
            'decided_at' => $decider ? now() : null,
        ]);
    }

    private function isRegistered(Customer $customer): bool
    {
        return in_array($customer->approval_status, self::REGISTERED, true);
    }

    /**
     * @throws ValidationException
     */
    private function assertPending(CustomerUpdateRequest $request): void
    {
        if ($request->status !== CustomerUpdateRequest::STATUS_PENDING) {
            throw ValidationException::withMessages(['status' => 'This change has already been decided.']);
        }
    }
}
