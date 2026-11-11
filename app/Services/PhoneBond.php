<?php

namespace App\Services;

use App\Enums\LoanMethod;
use App\Enums\LoanStatus;
use App\Integrations\Linda\LindaGateway;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\LoanSchedule;
use App\Models\PhoneBondDevice;
use App\Models\PhoneBondDeviceLoan;
use App\Models\PhoneBondIncident;
use App\Models\PhoneBondLockRequest;
use App\Models\PhoneBondReplacement;
use App\Models\SmsLog;
use App\Services\Sms\SmsSender;
use App\Services\Sms\SmsTemplates;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * eSIMU (the Phone Bond loan method): the customer's smartphone, enrolled with Linda Device Lock, secures the loan.
 *
 * - Registration happens before the loan exists: the customer and phone are sent to Linda, the enrollment QR is shown and
 *   the application can only be submitted once Linda reports the phone enrolled. If Linda cannot be reached the
 *   registration (with the application form) is kept as a draft and retried - Linda matches repeats by externalId / IMEI,
 *   so nothing is duplicated. A draft never enters the approval workflow.
 * - A settled device stays enrolled (Done) and may secure a later loan after inspection, without a new QR. A damaged,
 *   lost or stolen device cannot.
 * - The loan approval workflow, schedule and repayments are the existing ones. Every morning an instalment still not fully
 *   paid the day after its due date locks the phone; a payment that clears the arrears unlocks it at once.
 * - Release (removed from Linda in the Linda portal) and replacement (approved by someone other than the requester) keep
 *   the device's history; the loan, its balance and its schedule are never changed by eSIMU.
 */
class PhoneBond
{
    public const WARNING_TEMPLATE = SmsTemplates::ESIMU_LOCK_WARNING;

    public function __construct(
        private LindaGateway $linda,
        private LoanService $loans,
        private PhoneBondLocks $locks,
        private PhoneBondAudit $audit,
        private PhoneBondAlerts $alerts,
    ) {}

    /**
     * Start (or resume) registering a new phone for a customer: saved first, then sent to Linda.
     *
     * @param  array{imei: string, make: string, model: string, serial_number?: string|null}  $phone
     * @param  array<string, mixed>|null  $applicationDraft  the loan application form, kept while the registration is pending
     */
    public function startRegistration(Customer $customer, array $phone, ?array $applicationDraft, Employee $actor): PhoneBondDevice
    {
        $existing = PhoneBondDevice::where('company_id', $customer->company_id)->where('imei', $phone['imei'])->where('status', '!=', PhoneBondDevice::CLOSED)->first();
        if ($existing !== null && (int) $existing->customer_id !== (int) $customer->id) {
            throw ValidationException::withMessages(['imei' => 'This IMEI is registered to another customer.']);
        }
        if ($existing !== null && $existing->status !== PhoneBondDevice::PENDING) {
            throw ValidationException::withMessages(['imei' => 'This phone is already registered for the customer: choose Continue With Existing Device.']);
        }

        $device = $existing ?? new PhoneBondDevice([
            'company_id' => $customer->company_id,
            'branch_id' => $customer->branch_id,
            'customer_id' => $customer->id,
            'imei' => $phone['imei'],
            'status' => PhoneBondDevice::PENDING,
            'enrollment_status' => PhoneBondDevice::NOT_REGISTERED,
            'lock_state' => PhoneBondDevice::UNLOCKED,
            'registered_by' => $actor->id,
        ]);
        $device->fill([
            'brand' => $phone['make'],
            'model' => $phone['model'],
            'serial_number' => $phone['serial_number'] ?? $device->serial_number,
            'phone_number' => $customer->phone,
            'application_draft' => $applicationDraft ?? $device->application_draft,
        ])->save();

        if ($existing === null) {
            $this->audit->record($device, 'PhoneBond.registration_started', $actor, ['make' => $device->brand, 'model' => $device->model]);
        }

        return $this->registerWithLinda($device, $actor);
    }

    /**
     * Send the customer and phone to Linda (POST /registrations). Safe to repeat.
     */
    public function registerWithLinda(PhoneBondDevice $device, ?Employee $actor): PhoneBondDevice
    {
        if ($device->status === PhoneBondDevice::CLOSED || $device->isEnrolled()) {
            return $device;
        }

        $customer = $device->customer;
        $result = $this->linda->register($this->lindaCustomer($customer), [
            'externalId' => 'mkopa-device-'.$device->id,
            'imei' => $device->imei,
            'make' => $device->brand,
            'model' => $device->model,
            'serialNumber' => $device->serial_number,
        ]);

        if (! $result->ok) {
            $device->forceFill(['registration_error' => mb_substr((string) $result->message, 0, 500)])->save();
            $this->audit->record($device, 'PhoneBond.registration_failed', $actor, ['result' => 'failed', 'message' => $device->registration_error, 'retryable' => $result->retryable]);

            return $device;
        }

        $device->forceFill([
            'linda_device_id' => $result->get('device.id'),
            'linda_customer_id' => $result->get('customer.id'),
            'linda_status' => $result->get('device.status'),
            'enrollment_status' => PhoneBondDevice::ENROLLMENT_PENDING,
            'registration_error' => null,
        ])->save();
        $this->storeEnrollment($device, $result->get('device.enrollment'));
        $this->audit->record($device, 'PhoneBond.device_registered', $actor, ['result' => 'registered', 'linda_device_id' => $device->linda_device_id, 'linda_status' => $device->linda_status]);

        if (in_array($device->linda_status, ['ACTIVE', 'LOCKED'], true)) {
            $this->markEnrolled($device, $device->linda_status, null, $actor);
        }

        return $device->fresh();
    }

    /**
     * "Check enrollment": the phone counts as enrolled only when Linda says so (status ACTIVE or LOCKED), never because a QR
     * was shown. A pending phone gets a fresh QR when the previous one expired.
     */
    public function checkEnrollment(PhoneBondDevice $device, Employee $actor): PhoneBondDevice
    {
        if ($device->enrollment_status === PhoneBondDevice::NOT_REGISTERED) {
            return $this->registerWithLinda($device, $actor);
        }
        if ($device->isEnrolled() || $device->status === PhoneBondDevice::CLOSED) {
            return $device;
        }

        $result = $this->linda->device((string) $device->linda_device_id);
        if (! $result->ok) {
            throw ValidationException::withMessages(['device' => (string) $result->message]);
        }

        $status = (string) $result->get('status');
        $device->forceFill(['linda_status' => $status])->save();
        if (in_array($status, ['ACTIVE', 'LOCKED'], true)) {
            return $this->markEnrolled($device, $status, $result->get('lastCheckInAt'), $actor);
        }

        if ($device->enrollment_payload === null || $device->enrollment_expires_at?->isPast()) {
            $fresh = $this->linda->enrollment((string) $device->linda_device_id);
            if ($fresh->ok) {
                $this->storeEnrollment($device, $fresh->get('enrollment'));
            }
        }

        return $device->fresh();
    }

    /**
     * The enrollment QR: Linda's enrollment.qr object encoded as JSON, exactly as returned.
     */
    public function qrSvg(PhoneBondDevice $device, int $size = 280): ?string
    {
        $qr = $device->enrollment_payload['qr'] ?? null;
        if ($qr === null || $device->isEnrolled()) {
            return null;
        }

        return (new Writer(new ImageRenderer(new RendererStyle($size, 2), new SvgImageBackEnd)))
            ->writeString((string) json_encode($qr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * The Loan Officer's physical inspection of a device about to secure another loan.
     *
     * @param  'good'|'damaged'  $condition
     */
    public function inspect(PhoneBondDevice $device, string $condition, ?string $note, Employee $actor): PhoneBondDevice
    {
        if ($device->status === PhoneBondDevice::CLOSED) {
            throw ValidationException::withMessages(['device' => 'This device has been released.']);
        }
        if (in_array($device->condition, ['lost', 'stolen'], true)) {
            throw ValidationException::withMessages(['device' => 'This device was reported '.$device->condition.'.']);
        }

        $device->forceFill(['condition' => $condition, 'inspected_at' => now(), 'inspected_by' => $actor->id])->save();
        $this->audit->record($device, 'PhoneBond.inspected', $actor, ['condition' => $condition, 'note' => $note]);

        return $device;
    }

    /**
     * The customer's enrolled phones that may secure a new loan (Continue With Existing Device).
     *
     * @return Collection<int, PhoneBondDevice>
     */
    public function reusableDevices(Customer $customer): Collection
    {
        return PhoneBondDevice::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', [PhoneBondDevice::ACTIVE, PhoneBondDevice::DONE])
            ->where('enrollment_status', PhoneBondDevice::ENROLLED)
            ->where('condition', PhoneBondDevice::GOOD)
            ->with('loan')
            ->latest('id')
            ->get()
            ->reject(fn (PhoneBondDevice $device): bool => $this->securedLoan($device) !== null);
    }

    /**
     * The loan the device currently secures: an application in the approval pipeline or a loan still being repaid.
     */
    public function securedLoan(PhoneBondDevice $device): ?Loan
    {
        $busy = [...LoanStatus::inPipeline(), ...LoanStatus::repayable()];

        return Loan::query()
            ->whereIn('id', PhoneBondDeviceLoan::where('device_id', $device->id)->whereNull('unlinked_at')->select('loan_id'))
            ->whereIn('status', LoanStatus::values(...$busy))
            ->latest('id')
            ->first();
    }

    /**
     * The device the loan is secured by (its open link), if any.
     */
    public function deviceFor(Loan $loan): ?PhoneBondDevice
    {
        $link = PhoneBondDeviceLoan::where('loan_id', $loan->id)->whereNull('unlinked_at')->latest('id')->first();

        return $link?->device;
    }

    /**
     * Validate that a device may secure a new eSIMU application (before the loan is created).
     */
    public function assertCanSecure(Customer $customer, int $deviceId, bool $inspected, ?Loan $exceptLoan = null): PhoneBondDevice
    {
        $device = PhoneBondDevice::where('company_id', $customer->company_id)->find($deviceId);
        $fail = fn (string $message) => throw ValidationException::withMessages(['device_id' => $message]);

        if ($device === null || (int) $device->customer_id !== (int) $customer->id) {
            $fail('Choose a device registered for this customer.');
        }
        if (! $device->isEnrolled()) {
            $fail('The device has not completed Linda enrollment. The customer must scan the QR code and enrollment must be confirmed.');
        }
        if (! in_array($device->status, [PhoneBondDevice::ACTIVE, PhoneBondDevice::DONE], true)) {
            $fail('This device cannot secure a loan.');
        }
        if ($device->condition !== PhoneBondDevice::GOOD) {
            $fail('This device was found '.$device->condition.'. Register a new device instead.');
        }
        $secured = $this->securedLoan($device);
        if ($secured !== null && $secured->id !== $exceptLoan?->id) {
            $fail("This device already secures loan {$secured->loan_number}.");
        }
        $reused = $device->loanLinks()->when($exceptLoan !== null, fn ($query) => $query->where('loan_id', '!=', $exceptLoan->id))->exists();
        if ($reused && ! $inspected) {
            $fail('Confirm that the device was physically inspected and is in good working condition.');
        }

        return $device;
    }

    /**
     * The device secures the loan (application, top-up or approved replacement). Any device previously on the loan leaves it.
     *
     * @param  'application'|'top_up'|'replacement'  $reason
     */
    public function link(Loan $loan, PhoneBondDevice $device, string $reason, ?Employee $actor): void
    {
        DB::transaction(function () use ($loan, $device, $reason, $actor): void {
            $device = PhoneBondDevice::lockForUpdate()->findOrFail($device->id);
            $current = PhoneBondDeviceLoan::where('loan_id', $loan->id)->whereNull('unlinked_at')->get();
            if ($current->contains('device_id', $device->id)) {
                return;
            }
            foreach ($current as $link) {
                $this->unlink($link, 'Replaced on the loan by IMEI '.$device->imei);
            }
            foreach (PhoneBondDeviceLoan::where('device_id', $device->id)->whereNull('unlinked_at')->get() as $link) {
                $this->unlink($link, "Moved to loan {$loan->loan_number}");
            }

            PhoneBondDeviceLoan::create(['device_id' => $device->id, 'loan_id' => $loan->id, 'linked_by_reason' => $reason, 'linked_at' => now(), 'linked_by' => $actor?->id]);
            $device->forceFill(['loan_id' => $loan->id, 'status' => PhoneBondDevice::ACTIVE, 'application_draft' => null])->save();
            $this->audit->record($device, 'PhoneBond.linked_to_loan', $actor, ['loan_number' => $loan->loan_number, 'reason' => $reason], $loan->id);
        });
    }

    /**
     * A top-up keeps the enrolled device: it now secures the top-up loan (no new registration, no new QR). Linda keeps no
     * debt, so nothing is sent to Linda.
     */
    public function moveToTopUp(Loan $previous, Loan $topUp, ?Employee $actor): ?PhoneBondDevice
    {
        $device = $this->deviceFor($previous)
            ?? PhoneBondDevice::where('loan_id', $previous->id)->whereIn('status', [PhoneBondDevice::ACTIVE, PhoneBondDevice::DONE])->first();
        if ($device === null) {
            return null;
        }

        $this->link($topUp, $device, 'top_up', $actor);

        return $device->fresh();
    }

    /**
     * Release: the phone was removed from management in the Linda portal (Linda's API has no release endpoint). Allowed only
     * when no loan in the pipeline or still being repaid uses it and no replacement involving it is pending.
     */
    public function release(PhoneBondDevice $device, string $reason, Employee $actor): PhoneBondDevice
    {
        if ($device->status === PhoneBondDevice::CLOSED) {
            throw ValidationException::withMessages(['device' => 'This device is already released.']);
        }
        $secured = $this->securedLoan($device);
        if ($secured !== null) {
            throw ValidationException::withMessages(['device' => "Loan {$secured->loan_number} still uses this device as collateral. Release it once the loan is completed."]);
        }
        if (PhoneBondReplacement::where('status', PhoneBondReplacement::PENDING)->where(fn ($query) => $query->where('old_device_id', $device->id)->orWhere('new_device_id', $device->id))->exists()) {
            throw ValidationException::withMessages(['device' => 'A replacement request involving this device is still pending.']);
        }

        DB::transaction(function () use ($device, $reason, $actor): void {
            foreach (PhoneBondDeviceLoan::where('device_id', $device->id)->whereNull('unlinked_at')->get() as $link) {
                $this->unlink($link, 'Device released');
            }
            $device->forceFill(['status' => PhoneBondDevice::CLOSED, 'closed_reason' => trim($reason), 'closed_by' => $actor->id, 'closed_at' => now(), 'application_draft' => null])->save();
            $this->audit->record($device, 'PhoneBond.released', $actor, ['reason' => $device->closed_reason, 'result' => 'closed', 'released_in_linda_portal' => true]);
        });

        return $device->fresh();
    }

    /**
     * A lost, stolen or damaged report. The device and loan records and the debt stay as they are.
     *
     * @param  'lost'|'stolen'|'damaged'  $type
     */
    public function reportIncident(PhoneBondDevice $device, string $type, string $details, ?string $occurredOn, Employee $actor): PhoneBondIncident
    {
        if ($device->status === PhoneBondDevice::CLOSED) {
            throw ValidationException::withMessages(['device' => 'This device has been released.']);
        }

        return DB::transaction(function () use ($device, $type, $details, $occurredOn, $actor): PhoneBondIncident {
            $incident = PhoneBondIncident::create([
                'company_id' => $device->company_id,
                'device_id' => $device->id,
                'loan_id' => $device->loan_id,
                'type' => $type,
                'details' => trim($details),
                'occurred_on' => $occurredOn,
                'reported_by' => $actor->id,
            ]);
            $device->forceFill(['condition' => $type])->save();
            $this->audit->record($device, 'PhoneBond.incident_reported', $actor, ['incident_id' => $incident->id, 'type' => $type, 'details' => $incident->details]);

            return $incident;
        });
    }

    /**
     * The Loan Officer asks to replace the phone securing a running loan with a new phone that has already enrolled.
     *
     * @param  'lost'|'stolen'|'damaged'|'other'  $reasonType
     */
    public function requestReplacement(PhoneBondDevice $old, PhoneBondDevice $new, string $reasonType, string $reason, Employee $actor): PhoneBondReplacement
    {
        $loan = $this->securedLoan($old);
        if ($loan === null || ! in_array($loan->status, LoanStatus::repayable(), true)) {
            throw ValidationException::withMessages(['device' => 'Only the device securing a running eSIMU loan can be replaced.']);
        }
        if ($new->id === $old->id || (int) $new->customer_id !== (int) $old->customer_id) {
            throw ValidationException::withMessages(['new_device_id' => 'Choose another phone registered for the same customer.']);
        }
        if (! $new->isEnrolled() || $new->condition !== PhoneBondDevice::GOOD || ! in_array($new->status, [PhoneBondDevice::ACTIVE, PhoneBondDevice::DONE], true)) {
            throw ValidationException::withMessages(['new_device_id' => 'The new phone must have completed Linda enrollment and be in good condition.']);
        }
        if ($this->securedLoan($new) !== null) {
            throw ValidationException::withMessages(['new_device_id' => 'The new phone already secures another loan.']);
        }
        if (PhoneBondReplacement::where('loan_id', $loan->id)->where('status', PhoneBondReplacement::PENDING)->exists()) {
            throw ValidationException::withMessages(['device' => 'A replacement request for this loan is already pending.']);
        }

        $replacement = PhoneBondReplacement::create([
            'company_id' => $old->company_id,
            'branch_id' => $old->branch_id,
            'loan_id' => $loan->id,
            'old_device_id' => $old->id,
            'new_device_id' => $new->id,
            'reason_type' => $reasonType,
            'reason' => trim($reason),
            'requested_by' => $actor->id,
        ]);
        $this->audit->record($old, 'PhoneBond.replacement_requested', $actor, ['replacement_id' => $replacement->id, 'new_device_id' => $new->id, 'new_imei' => $new->imei, 'reason_type' => $reasonType, 'reason' => $replacement->reason], $loan->id);
        $this->alerts->toStaff($old, 'phone_bond.replace_approve', 'esimu_replacement', 'eSIMU device replacement awaiting approval',
            "{$actor->full_name} asked to replace IMEI {$old->imei} with IMEI {$new->imei} on loan {$loan->loan_number}.", $actor);

        return $replacement;
    }

    /**
     * Approve or reject a replacement. Nobody decides their own request. On approval the new phone secures the same loan;
     * the old phone leaves it but stays enrolled with Linda (release is a separate, authorised decision).
     */
    public function decideReplacement(PhoneBondReplacement $replacement, bool $approve, ?string $note, Employee $actor): PhoneBondReplacement
    {
        if ($replacement->status !== PhoneBondReplacement::PENDING) {
            throw ValidationException::withMessages(['replacement' => 'This request has already been decided.']);
        }
        if ((int) $replacement->requested_by === (int) $actor->id) {
            throw ValidationException::withMessages(['replacement' => 'You cannot decide your own replacement request.']);
        }

        return DB::transaction(function () use ($replacement, $approve, $note, $actor): PhoneBondReplacement {
            $replacement = PhoneBondReplacement::lockForUpdate()->findOrFail($replacement->id);
            $old = $replacement->oldDevice;
            $new = $replacement->newDevice;
            $loan = $replacement->loan;

            if ($approve) {
                if (! $new->isEnrolled() || $new->condition !== PhoneBondDevice::GOOD || $this->securedLoan($new) !== null) {
                    throw ValidationException::withMessages(['replacement' => 'The new phone is no longer enrolled, in good condition and free.']);
                }
                $this->link($loan, $new, 'replacement', $actor);
                $old->refresh()->forceFill([
                    'status' => PhoneBondDevice::DONE,
                    'condition' => in_array($replacement->reason_type, ['lost', 'stolen', 'damaged'], true) ? $replacement->reason_type : $old->condition,
                ])->save();
            }

            $replacement->forceFill([
                'status' => $approve ? PhoneBondReplacement::APPROVED : PhoneBondReplacement::REJECTED,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $note !== null ? mb_substr(trim($note), 0, 500) : null,
            ])->save();
            $this->audit->record($old, $approve ? 'PhoneBond.replacement_approved' : 'PhoneBond.replacement_rejected', $actor, [
                'replacement_id' => $replacement->id, 'new_device_id' => $new->id, 'new_imei' => $new->imei, 'note' => $replacement->decision_note,
            ], $loan->id);

            return $replacement;
        });
    }

    /**
     * Every morning (local time): a settled loan makes its device Done and unlocks it; an instalment still not fully paid
     * after its due date (plus grace days) locks the phone; a loan with no arrears unlocks a locked phone.
     *
     * @return array{locked: int, unlocked: int, done: int}
     */
    public function enforce(CarbonImmutable $today): array
    {
        $counts = ['locked' => 0, 'unlocked' => 0, 'done' => 0];
        $graceDays = (int) config('integrations.linda.grace_days', 0);

        PhoneBondDevice::query()->where('status', PhoneBondDevice::ACTIVE)->where('enrollment_status', PhoneBondDevice::ENROLLED)
            ->with('loan')->get()
            ->each(function (PhoneBondDevice $device) use ($today, $graceDays, &$counts): void {
                $loan = $device->loan;
                if ($loan === null || in_array($loan->status, LoanStatus::inPipeline(), true)) {
                    return;
                }
                if (! in_array($loan->status, LoanStatus::repayable(), true)) {
                    $this->settle($device, $loan) && $counts['done']++;
                    if ($loan->status === LoanStatus::Closed && $device->lock_state === PhoneBondDevice::LOCKED) {
                        $this->safely(fn () => $this->locks->request($device, 'unlock', 'auto', 'Loan fully repaid', null)) && $counts['unlocked']++;
                    }

                    return;
                }

                $overdue = $this->hasArrears($loan, $today->subDays($graceDays));
                if ($overdue && $device->lock_state === PhoneBondDevice::UNLOCKED) {
                    $this->safely(fn () => $this->locks->request($device, 'lock', 'auto', 'Instalment not fully paid by its due date', null)) && $counts['locked']++;
                } elseif (! $overdue && $device->lock_state === PhoneBondDevice::LOCKED) {
                    $this->safely(fn () => $this->locks->request($device, 'unlock', 'auto', 'Required payment received', null)) && $counts['unlocked']++;
                }
            });

        return $counts;
    }

    /**
     * Right after a repayment is posted (or the loan closes): unlock a locked phone whose arrears are cleared, and mark the
     * device Done when the loan is settled. Never locks: locking is the morning run's decision.
     */
    public function afterRepayment(Loan $loan): void
    {
        if ($loan->loan_method !== LoanMethod::PhoneBond) {
            return;
        }

        rescue(function () use ($loan): void {
            $loan = $loan->fresh();
            $device = $this->deviceFor($loan);
            if ($device === null || $device->status !== PhoneBondDevice::ACTIVE) {
                return;
            }

            if ($loan->status === LoanStatus::Closed) {
                $this->settle($device, $loan);
                if ($device->lock_state === PhoneBondDevice::LOCKED) {
                    $this->safely(fn () => $this->locks->request($device, 'unlock', 'auto', 'Loan fully repaid', null));
                }

                return;
            }

            if ($device->lock_state === PhoneBondDevice::LOCKED && ! $this->hasArrears($loan, self::today())) {
                $this->safely(fn () => $this->locks->request($device, 'unlock', 'auto', 'Required payment received', null));
            }
        });
    }

    /**
     * The evening of a due date (local time): warn the customer whose instalment due today is not fully paid that the phone
     * may be locked. One warning per instalment.
     */
    public function warn(CarbonImmutable $today, SmsTemplates $templates, SmsSender $sender): int
    {
        $sent = 0;
        $schedules = LoanSchedule::query()
            ->whereDate('due_date', $today->toDateString())
            ->whereColumn('paid_amount', '<', 'amount')
            ->whereHas('loan', fn ($query) => $query->where('loan_method', LoanMethod::PhoneBond->value)->whereIn('status', LoanStatus::values(...LoanStatus::repayable())))
            ->with('loan.customer', 'loan.company')
            ->orderBy('id')->get();

        foreach ($schedules as $schedule) {
            $loan = $schedule->loan;
            $template = $templates->template($loan->company_id, self::WARNING_TEMPLATE);
            $reference = self::WARNING_TEMPLATE.':'.$schedule->id;
            if (! $template->is_active || $this->deviceFor($loan) === null || SmsSender::normalisePhone($loan->customer?->phone) === null
                || SmsLog::where('company_id', $loan->company_id)->where('reference', $reference)->exists()) {
                continue;
            }

            $sender->send($loan->company_id, (string) $loan->customer->phone, SmsTemplates::fill($template->body, [
                'name' => $loan->customer->first_name,
                'amount' => money((float) $schedule->amount - (float) $schedule->paid_amount),
                'due_date' => $schedule->due_date->format('d/m/Y'),
                'balance' => money($this->loans->outstanding($loan)['total']),
                'loan_number' => $loan->reference_number ?? $loan->loan_number,
                'company' => $loan->company?->name,
            ]), 'overdue', ['customer_id' => $loan->customer_id, 'reference' => $reference]);
            $sent++;
        }

        return $sent;
    }

    /**
     * Daily reconciliation with Linda (its best practice): Linda's status and last check-in for every managed device.
     * A device with no pending command takes Linda's confirmed lock state.
     */
    public function reconcile(): int
    {
        $updated = 0;
        PhoneBondDevice::query()->whereIn('status', [PhoneBondDevice::ACTIVE, PhoneBondDevice::DONE])->where('enrollment_status', PhoneBondDevice::ENROLLED)
            ->whereNotNull('linda_device_id')->orderBy('id')->get()
            ->each(function (PhoneBondDevice $device) use (&$updated): void {
                $result = $this->linda->device((string) $device->linda_device_id);
                if (! $result->ok) {
                    return;
                }
                $status = (string) $result->get('status');
                $changes = ['linda_status' => $status, 'last_seen_at' => $result->get('lastCheckInAt') ? CarbonImmutable::parse($result->get('lastCheckInAt')) : $device->last_seen_at];
                $pending = PhoneBondLockRequest::where('device_id', $device->id)->where('status', PhoneBondLockRequest::PENDING)->exists();
                if (! $pending && in_array($status, ['ACTIVE', 'LOCKED'], true)) {
                    $changes['lock_state'] = $status === 'LOCKED' ? PhoneBondDevice::LOCKED : PhoneBondDevice::UNLOCKED;
                }
                $device->forceFill($changes)->save();
                $updated++;
                if (app()->environment('production')) {
                    usleep(1_100_000);
                }
            });

        return $updated;
    }

    /**
     * The actual amount Finance disbursed for the loan (the eSIMU debt basis shown with the device).
     */
    public function disbursedAmount(Loan $loan): ?float
    {
        $amount = LoanDisbursement::where('loan_id', $loan->id)->where('status', LoanDisbursement::SUCCESS)->latest('id')->value('amount');

        return $amount !== null ? (float) $amount : null;
    }

    /**
     * Arrears: an instalment due before the date that is not fully paid (a partial payment is not a full payment).
     */
    public function hasArrears(Loan $loan, CarbonImmutable $before): bool
    {
        return $loan->schedules()->whereDate('due_date', '<', $before->toDateString())->whereColumn('paid_amount', '<', 'amount')->exists();
    }

    /**
     * Today in the eSIMU local time zone.
     */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('integrations.linda.timezone', 'Africa/Dar_es_Salaam'))->startOfDay();
    }

    /**
     * eSIMU reports for the branch scope and the period.
     *
     * @param  list<int>|null  $branchIds
     * @return array<string, mixed>
     */
    public function report(int $companyId, ?array $branchIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $devices = fn () => PhoneBondDevice::query()->where('company_id', $companyId)->when($branchIds !== null, fn ($query) => $query->whereIn('branch_id', $branchIds));

        return [
            'cards' => [
                'total_devices' => $devices()->where('status', '!=', PhoneBondDevice::PENDING)->count(),
                'pending_devices' => $devices()->where('status', PhoneBondDevice::PENDING)->count(),
                'active_devices' => $devices()->where('status', PhoneBondDevice::ACTIVE)->count(),
                'done_devices' => $devices()->where('status', PhoneBondDevice::DONE)->count(),
                'closed_devices' => $devices()->where('status', PhoneBondDevice::CLOSED)->count(),
                'locked_devices' => $devices()->where('lock_state', PhoneBondDevice::LOCKED)->where('status', '!=', PhoneBondDevice::CLOSED)->count(),
                'pending_commands' => $devices()->whereIn('lock_state', [PhoneBondDevice::LOCK_PENDING, PhoneBondDevice::UNLOCK_PENDING])->count(),
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
            'command_id' => $request->command_id,
            'command_status' => $request->command_status,
            'provider_reference' => $request->provider_reference,
            'provider_message' => $request->provider_message,
            'requested_by' => $request->requester ? trim($request->requester->first_name.' '.$request->requester->last_name) : ($request->trigger === 'auto' ? 'System' : null),
            'requested_at' => $request->created_at?->toDateTimeString(),
            'confirmed_at' => $request->confirmed_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array{externalId: string, fullName: string, phone: string, nationalId: string|null, idDocumentType: string|null, address: string|null}
     */
    private function lindaCustomer(Customer $customer): array
    {
        [$idType, $idNumber] = match (true) {
            filled($customer->nida_number) => ['NATIONAL_ID', $customer->nida_number],
            filled($customer->national_id_number) => ['NATIONAL_ID', $customer->national_id_number],
            filled($customer->voter_id_number) => ['VOTER_ID', $customer->voter_id_number],
            filled($customer->driver_licence_number) => ['DRIVING_LICENSE', $customer->driver_licence_number],
            filled($customer->passport_number) => ['PASSPORT', $customer->passport_number],
            default => [null, null],
        };

        return [
            'externalId' => 'mkopa-customer-'.$customer->id,
            'fullName' => mb_substr(trim((string) $customer->full_name), 0, 160),
            'phone' => SmsSender::normalisePhone($customer->phone) ?? (string) $customer->phone,
            'nationalId' => $idNumber,
            'idDocumentType' => $idType,
            'address' => collect([$customer->street, $customer->ward, $customer->district, $customer->region?->name])->filter()->implode(', ') ?: null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $enrollment
     */
    private function storeEnrollment(PhoneBondDevice $device, ?array $enrollment): void
    {
        if ($enrollment === null) {
            return;
        }

        $device->forceFill([
            'enrollment_payload' => ['qr' => $enrollment['qr'] ?? null, 'token' => $enrollment['token'] ?? null],
            'enrollment_expires_at' => filled($enrollment['expiresAt'] ?? null) ? CarbonImmutable::parse($enrollment['expiresAt']) : null,
        ])->save();
    }

    private function markEnrolled(PhoneBondDevice $device, string $lindaStatus, ?string $lastCheckIn, ?Employee $actor): PhoneBondDevice
    {
        $device->forceFill([
            'enrollment_status' => PhoneBondDevice::ENROLLED,
            'enrolled_at' => $device->enrolled_at ?? now(),
            'enrollment_payload' => null,
            'enrollment_expires_at' => null,
            'linda_status' => $lindaStatus,
            'lock_state' => $lindaStatus === 'LOCKED' ? PhoneBondDevice::LOCKED : PhoneBondDevice::UNLOCKED,
            'last_seen_at' => $lastCheckIn ? CarbonImmutable::parse($lastCheckIn) : $device->last_seen_at,
            'status' => $device->status === PhoneBondDevice::PENDING ? PhoneBondDevice::ACTIVE : $device->status,
        ])->save();
        $this->audit->record($device, 'PhoneBond.enrolled', $actor, ['result' => 'enrolled', 'linda_status' => $lindaStatus]);

        return $device;
    }

    private function settle(PhoneBondDevice $device, Loan $loan): bool
    {
        if ($device->status !== PhoneBondDevice::ACTIVE) {
            return false;
        }

        $device->forceFill(['status' => PhoneBondDevice::DONE])->save();
        $this->audit->record($device, 'PhoneBond.done', null, ['loan_status' => $loan->status->value, 'result' => 'done'], $loan->id);

        return true;
    }

    private function unlink(PhoneBondDeviceLoan $link, string $reason): void
    {
        $link->forceFill(['unlinked_at' => now(), 'unlinked_reason' => mb_substr($reason, 0, 255)])->save();
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
}
