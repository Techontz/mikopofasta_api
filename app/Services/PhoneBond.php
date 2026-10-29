<?php

namespace App\Services;

use App\Enums\LoanStatus;
use App\Integrations\Linda\LindaGateway;
use App\Integrations\Linda\LindaResult;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\PhoneBondDevice;
use App\Models\PhoneBondLockRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phone Bond within M-KopaCredit (enhancement §5): the customer's phone, enrolled with Linda, secures the loan.
 *
 * - Only Traders/Entrepreneurs, Private Sector Employees and University/College Students may take it (never Public Servants
 *   or Retired Public Servants — `integrations.linda.excluded_customer_types`).
 * - A loan whose due instalment is still unpaid after the grace day is locked through Linda automatically; once it has no
 *   arrears it is unlocked. Authorised staff may lock or unlock by hand, with a reason.
 * - Nothing is reported locked or unlocked before Linda confirms it: a request Linda only accepts stays pending, a refusal
 *   (or an unconfigured integration) fails and leaves the device as it was. Staff who acted in the Linda portal record its
 *   reference to confirm.
 * - A repaid loan makes the device Done (enrollment kept); Closed needs an authorised reason; a top-up keeps the device and
 *   its enrollment and sends Linda the new debt.
 */
class PhoneBond
{
    public function __construct(
        private LindaGateway $linda,
        private LoanService $loans,
    ) {}

    /**
     * @throws ValidationException
     */
    public static function assertEligible(Customer $customer): void
    {
        $code = $customer->customerCategory?->code;
        if ($code !== null && in_array($code, config('integrations.linda.excluded_customer_types', []), true)) {
            throw ValidationException::withMessages(['category_id' => 'Phone Bond is not available to Public Servants or Retired Public Servants.']);
        }
    }

    /**
     * @param  array{imei: string, serial_number?: string|null, brand?: string|null, model?: string|null, phone_number?: string|null, linda_device_id?: string|null, sim_protection?: bool|null}  $data
     */
    public function register(Loan $loan, array $data, Employee $actor): PhoneBondDevice
    {
        self::assertEligible($loan->customer);
        if ($loan->category?->loan_method !== 'phone_bond') {
            throw ValidationException::withMessages(['loan_id' => 'Only a Phone Bond loan takes a device.']);
        }
        if (PhoneBondDevice::where('loan_id', $loan->id)->whereIn('status', [PhoneBondDevice::ACTIVE, PhoneBondDevice::DONE])->exists()) {
            throw ValidationException::withMessages(['loan_id' => 'This loan already has a device.']);
        }
        if (PhoneBondDevice::where('company_id', $loan->company_id)->where('imei', $data['imei'])->exists()) {
            throw ValidationException::withMessages(['imei' => 'This IMEI is already registered.']);
        }

        $device = PhoneBondDevice::create($data + [
            'company_id' => $loan->company_id,
            'branch_id' => $loan->branch_id,
            'customer_id' => $loan->customer_id,
            'loan_id' => $loan->id,
            'enrollment_status' => filled($data['linda_device_id'] ?? null) ? 'enrolled' : 'pending',
            'status' => PhoneBondDevice::ACTIVE,
            'lock_state' => PhoneBondDevice::UNLOCKED,
            'registered_by' => $actor->id,
        ]);
        $this->audit($device, 'PhoneBond.device_registered', $actor, ['imei' => $device->imei, 'loan_id' => $loan->id]);

        return $device;
    }

    /**
     * Ask Linda to lock or unlock. The device changes only on Linda's confirmation.
     *
     * @param  'lock'|'unlock'  $action
     */
    public function request(PhoneBondDevice $device, string $action, string $trigger, string $reason, ?Employee $actor): PhoneBondLockRequest
    {
        return DB::transaction(function () use ($device, $action, $trigger, $reason, $actor): PhoneBondLockRequest {
            $device = PhoneBondDevice::lockForUpdate()->findOrFail($device->id);
            if ($device->status === PhoneBondDevice::CLOSED) {
                throw ValidationException::withMessages(['device' => 'A closed device is no longer with Linda.']);
            }
            $open = PhoneBondLockRequest::where('device_id', $device->id)->whereIn('action', ['lock', 'unlock'])->where('status', PhoneBondLockRequest::PENDING)->first();
            if ($open !== null) {
                throw ValidationException::withMessages(['device' => "A {$open->action} request is still waiting for Linda's confirmation."]);
            }

            $result = $action === 'lock' ? $this->linda->lock($device, $reason) : $this->linda->unlock($device, $reason);
            $request = $this->store($device, $action, $trigger, $reason, $actor, $result);

            if ($request->status === PhoneBondLockRequest::CONFIRMED) {
                $device->forceFill(['lock_state' => $action === 'lock' ? PhoneBondDevice::LOCKED : PhoneBondDevice::UNLOCKED])->save();
            } elseif ($request->status === PhoneBondLockRequest::PENDING) {
                $device->forceFill(['lock_state' => $action === 'lock' ? PhoneBondDevice::LOCK_PENDING : PhoneBondDevice::UNLOCK_PENDING])->save();
            }

            return $request;
        });
    }

    /**
     * Linda's confirmation of a pending (or refused-by-integration) request: staff record Linda's reference.
     */
    public function confirm(PhoneBondLockRequest $request, string $providerReference, Employee $actor): PhoneBondLockRequest
    {
        return DB::transaction(function () use ($request, $providerReference, $actor): PhoneBondLockRequest {
            $request = PhoneBondLockRequest::lockForUpdate()->findOrFail($request->id);
            if ($request->status === PhoneBondLockRequest::CONFIRMED) {
                throw ValidationException::withMessages(['request' => 'This request is already confirmed.']);
            }
            if (PhoneBondLockRequest::where('device_id', $request->device_id)->where('id', '>', $request->id)->whereIn('action', ['lock', 'unlock'])->exists()) {
                throw ValidationException::withMessages(['request' => 'A later request exists for this device; confirm that one instead.']);
            }

            $request->forceFill(['status' => PhoneBondLockRequest::CONFIRMED, 'provider_reference' => mb_substr(trim($providerReference), 0, 100), 'confirmed_at' => now(), 'confirmed_by' => $actor->id])->save();
            if (in_array($request->action, ['lock', 'unlock'], true)) {
                PhoneBondDevice::whereKey($request->device_id)->update(['lock_state' => $request->action === 'lock' ? PhoneBondDevice::LOCKED : PhoneBondDevice::UNLOCKED]);
            }
            $this->audit($request->device, 'PhoneBond.request_confirmed', $actor, ['request_id' => $request->id, 'action' => $request->action, 'reference' => $request->provider_reference]);

            return $request;
        });
    }

    /**
     * Close a device: removed from Linda's service through an authorised process, with the reason.
     */
    public function close(PhoneBondDevice $device, string $reason, Employee $actor): PhoneBondDevice
    {
        if ($device->status === PhoneBondDevice::CLOSED) {
            throw ValidationException::withMessages(['device' => 'This device is already closed.']);
        }
        if ($device->loan !== null && in_array($device->loan->status, LoanStatus::repayable(), true)) {
            throw ValidationException::withMessages(['device' => 'The loan is still being repaid. Close the device once the loan is repaid, or record why (e.g. a damaged device) after Finance agrees.']);
        }

        $device->forceFill(['status' => PhoneBondDevice::CLOSED, 'closed_reason' => trim($reason), 'closed_by' => $actor->id, 'closed_at' => now()])->save();
        $this->audit($device, 'PhoneBond.device_closed', $actor, ['reason' => $device->closed_reason]);

        return $device;
    }

    /**
     * Daily: Done for repaid loans; lock after the grace day when the due instalment is unpaid; unlock when nothing is overdue.
     *
     * @return array{locked: int, unlocked: int, done: int}
     */
    public function enforce(CarbonImmutable $today): array
    {
        $counts = ['locked' => 0, 'unlocked' => 0, 'done' => 0];
        $graceDays = (int) config('integrations.linda.grace_days', 1);

        PhoneBondDevice::query()->where('status', PhoneBondDevice::ACTIVE)->with('loan')->each(function (PhoneBondDevice $device) use ($today, $graceDays, &$counts): void {
            $loan = $device->loan;
            if ($loan === null) {
                return;
            }
            if ($loan->status === LoanStatus::Closed) {
                $device->forceFill(['status' => PhoneBondDevice::DONE])->save();
                $counts['done']++;
                if (in_array($device->lock_state, [PhoneBondDevice::LOCKED, PhoneBondDevice::LOCK_PENDING], true)) {
                    $this->safely(fn () => $this->request($device, 'unlock', 'auto', 'Loan repaid', null)) && $counts['unlocked']++;
                }

                return;
            }

            $overdue = $loan->schedules()->whereDate('due_date', '<', $today->subDays($graceDays)->toDateString())->whereColumn('paid_amount', '<', 'amount')->exists();
            if ($overdue && $device->lock_state === PhoneBondDevice::UNLOCKED) {
                $this->safely(fn () => $this->request($device, 'lock', 'auto', "Instalment unpaid after the {$graceDays}-day grace period", null)) && $counts['locked']++;
            } elseif (! $overdue && $device->lock_state === PhoneBondDevice::LOCKED) {
                $this->safely(fn () => $this->request($device, 'unlock', 'auto', 'Required payment received', null)) && $counts['unlocked']++;
            }
        });

        return $counts;
    }

    /**
     * A Phone Bond top-up keeps the device and its enrollment (no reset, no re-enrollment) and sends Linda the new debt.
     */
    public function moveToTopUp(Loan $previous, Loan $topUp, ?Employee $actor): ?PhoneBondLockRequest
    {
        $device = PhoneBondDevice::where('loan_id', $previous->id)->whereIn('status', [PhoneBondDevice::ACTIVE, PhoneBondDevice::DONE])->first();
        if ($device === null) {
            return null;
        }

        $device->forceFill(['loan_id' => $topUp->id, 'status' => PhoneBondDevice::ACTIVE])->save();
        $debt = $this->loans->outstanding($topUp->fresh())['total'];
        $result = $this->linda->updateDebt($device, $debt);
        $request = $this->store($device, 'debt_update', 'top_up', "Top-up {$topUp->loan_number} replaces {$previous->loan_number}", $actor, $result, $debt);
        $this->audit($device, 'PhoneBond.moved_to_top_up', $actor, ['from_loan_id' => $previous->id, 'to_loan_id' => $topUp->id, 'debt' => $debt, 'request_id' => $request->id]);

        return $request;
    }

    /**
     * Phone Bond reports (enhancement §5.4) for the branch scope and the period.
     *
     * @param  list<int>|null  $branchIds
     * @return array<string, mixed>
     */
    public function report(int $companyId, ?array $branchIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $devices = fn () => PhoneBondDevice::query()->where('company_id', $companyId)->when($branchIds !== null, fn ($query) => $query->whereIn('branch_id', $branchIds));
        $loanIds = $devices()->whereNotNull('loan_id')->pluck('loan_id');
        $openLoans = Loan::whereIn('id', $loanIds)->whereIn('status', LoanStatus::values(...LoanStatus::repayable()))->get();

        return [
            'cards' => [
                'total_devices' => $devices()->count(),
                'active_devices' => $devices()->where('status', PhoneBondDevice::ACTIVE)->count(),
                'done_devices' => $devices()->where('status', PhoneBondDevice::DONE)->count(),
                'closed_devices' => $devices()->where('status', PhoneBondDevice::CLOSED)->count(),
                'locked_devices' => $devices()->where('lock_state', PhoneBondDevice::LOCKED)->count(),
                'unlocked_devices' => $devices()->where('lock_state', PhoneBondDevice::UNLOCKED)->where('status', '!=', PhoneBondDevice::CLOSED)->count(),
                'expected_collection' => round((float) DB::table('loan_schedules')->whereIn('loan_id', $loanIds)->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])->sum('amount'), 2),
                'paid' => round((float) DB::table('loan_transactions')->whereIn('loan_id', $loanIds)->where('type', 'deposit')->whereNull('reversed_at')->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])->sum('amount'), 2),
                'outstanding_loans' => $openLoans->count(),
                'outstanding_amount' => round($openLoans->sum(fn (Loan $loan): float => $this->loans->outstanding($loan)['total']), 2),
            ],
            'history' => PhoneBondLockRequest::query()->where('company_id', $companyId)
                ->whereIn('device_id', $devices()->select('id'))
                ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
                ->with(['device:id,imei,customer_id', 'device.customer:id,first_name,middle_name,last_name', 'requester:id,first_name,last_name'])
                ->latest('id')->limit(500)->get()
                ->map(fn (PhoneBondLockRequest $request): array => $this->presentRequest($request))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentRequest(PhoneBondLockRequest $request): array
    {
        return [
            'id' => $request->id,
            'device_id' => $request->device_id,
            'imei' => $request->device?->imei,
            'customer' => $request->device?->customer?->full_name,
            'action' => $request->action,
            'trigger' => $request->trigger,
            'reason' => $request->reason,
            'status' => $request->status,
            'provider_reference' => $request->provider_reference,
            'provider_message' => $request->provider_message,
            'requested_by' => $request->requester ? trim($request->requester->first_name.' '.$request->requester->last_name) : ($request->trigger === 'auto' ? 'System' : null),
            'requested_at' => $request->created_at?->toDateTimeString(),
            'confirmed_at' => $request->confirmed_at?->toDateTimeString(),
        ];
    }

    private function store(PhoneBondDevice $device, string $action, string $trigger, string $reason, ?Employee $actor, LindaResult $result, ?float $amount = null): PhoneBondLockRequest
    {
        $request = PhoneBondLockRequest::create([
            'company_id' => $device->company_id,
            'device_id' => $device->id,
            'loan_id' => $device->loan_id,
            'action' => $action,
            'trigger' => $trigger,
            'reason' => mb_substr(trim($reason), 0, 500),
            'requested_by' => $actor?->id,
            'status' => $result->confirmed ? PhoneBondLockRequest::CONFIRMED : ($result->accepted ? PhoneBondLockRequest::PENDING : PhoneBondLockRequest::FAILED),
            'provider_reference' => $result->reference,
            'provider_message' => $result->message ? mb_substr($result->message, 0, 500) : null,
            'provider_response' => $result->response ?: null,
            'amount' => $amount,
            'confirmed_at' => $result->confirmed ? now() : null,
        ]);
        $this->audit($device, "PhoneBond.{$action}_requested", $actor, ['request_id' => $request->id, 'trigger' => $trigger, 'reason' => $request->reason, 'status' => $request->status, 'provider_message' => $request->provider_message]);

        return $request;
    }

    private function safely(callable $action): bool
    {
        try {
            $action();

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(PhoneBondDevice $device, string $action, ?Employee $actor, array $after): void
    {
        AuditLog::create([
            'company_id' => $device->company_id,
            'employee_id' => $actor?->id,
            'action' => $action,
            'auditable_type' => $device->getMorphClass(),
            'auditable_id' => $device->id,
            'after' => $after,
            'ip_address' => request()?->ip(),
        ]);
    }
}
