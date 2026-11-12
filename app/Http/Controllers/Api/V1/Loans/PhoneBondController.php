<?php

namespace App\Http\Controllers\Api\V1\Loans;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\PhoneBondDevice;
use App\Models\PhoneBondDeviceLoan;
use App\Models\PhoneBondIncident;
use App\Models\PhoneBondLockRequest;
use App\Models\PhoneBondReplacement;
use App\Services\AccessControl;
use App\Services\PhoneBond;
use App\Services\PhoneBondLocks;
use App\Services\PhoneBondPayments;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * eSIMU (the Phone Bond loan method) → Devices and Payments ({@see PhoneBond}, {@see PhoneBondLocks}), always within the
 * employee's branch scope. Every restricted action checks its own permission here, not only in the interface.
 */
class PhoneBondController extends LoanApiController
{
    /**
     * @var list<string>
     */
    private const VIEW = ['phone_bond.manage', 'phone_bond.lock', 'phone_bond.release', 'phone_bond.replace_request', 'phone_bond.replace_approve', 'loans.view'];

    public function __construct(
        private PhoneBond $phoneBond,
        private PhoneBondLocks $locks,
    ) {}

    /**
     * GET phone-bond/devices - status tab (pending, active, done, closed, all), lock state, branch and search by customer
     * name, phone, IMEI or loan number.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny(...self::VIEW);

        $devices = $this->scoped(PhoneBondDevice::query())
            ->with(['customer:id,first_name,middle_name,last_name,phone,customer_number', 'branch:id,name', 'loan:id,loan_number,status'])
            ->when($request->filled('status') && $request->input('status') !== 'all', fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('lock_state') && $request->input('lock_state') !== 'all', fn ($query) => $query->where('lock_state', $request->string('lock_state')->toString()))
            ->when($request->filled('customer_id'), fn ($query) => $query->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $like = '%'.trim($request->string('search')->toString()).'%';
                $query->where(fn ($inner) => $inner->where('imei', 'like', $like)
                    ->orWhereHas('customer', fn ($customer) => $customer->whereRaw("CONCAT_WS(' ', first_name, middle_name, last_name) like ?", [$like])
                        ->orWhereRaw("CONCAT_WS(' ', first_name, last_name) like ?", [$like])->orWhere('phone', 'like', $like)->orWhere('customer_number', 'like', $like))
                    ->orWhereHas('loanLinks.loan', fn ($loan) => $loan->where('loan_number', 'like', $like)->orWhere('reference_number', 'like', $like)));
            })
            ->latest('id');
        $this->applyFilters($devices, $request);

        return response()->json(['data' => $devices->limit(1000)->get()->map(fn (PhoneBondDevice $device): array => $this->present($device))->all()]);
    }

    /**
     * GET phone-bond/devices/{device} - the device with its loans, lock / unlock, replacement, incident and audit history.
     */
    public function show(PhoneBondDevice $device): JsonResponse
    {
        $this->authorizeAny(...self::VIEW);
        $this->assertVisible($device);
        $device->load(['customer', 'branch', 'loan']);

        return response()->json(['data' => $this->present($device, true) + [
            'history' => $device->requests()->with('requester:id,first_name,last_name')->latest('id')->get()
                ->map(fn (PhoneBondLockRequest $request): array => $this->phoneBond->presentRequest($request->setRelation('device', $device)))->all(),
            'loans' => $device->loanLinks()->with('loan:id,loan_number,status,amount_approved')->latest('id')->get()->map(fn (PhoneBondDeviceLoan $link): array => [
                'loan_id' => $link->loan_id,
                'loan_number' => $link->loan?->loan_number,
                'loan_status' => $link->loan?->status?->label(),
                'disbursed_amount' => $link->loan ? $this->phoneBond->disbursedAmount($link->loan) : null,
                'linked_by_reason' => $link->linked_by_reason,
                'linked_at' => $link->linked_at?->toDateTimeString(),
                'unlinked_at' => $link->unlinked_at?->toDateTimeString(),
                'unlinked_reason' => $link->unlinked_reason,
            ])->all(),
            'replacements' => PhoneBondReplacement::where(fn ($query) => $query->where('old_device_id', $device->id)->orWhere('new_device_id', $device->id))
                ->with(['oldDevice:id,imei', 'newDevice:id,imei', 'requester:id,first_name,last_name', 'decider:id,first_name,last_name', 'loan:id,loan_number'])
                ->latest('id')->get()->map(fn (PhoneBondReplacement $replacement): array => $this->presentReplacement($replacement))->all(),
            'incidents' => $device->incidents()->with('reporter:id,first_name,last_name')->latest('id')->get()->map(fn (PhoneBondIncident $incident): array => [
                'id' => $incident->id,
                'type' => $incident->type,
                'details' => $incident->details,
                'occurred_on' => $incident->occurred_on?->toDateString(),
                'reported_by' => $incident->reporter?->full_name,
                'reported_at' => $incident->created_at?->toDateTimeString(),
            ])->all(),
            'audit' => AuditLog::where('auditable_type', $device->getMorphClass())->where('auditable_id', $device->id)->where('action', 'like', 'PhoneBond.%')
                ->with('employee:id,first_name,last_name')->latest('id')->limit(200)->get()->map(fn (AuditLog $log): array => [
                    'action' => $log->action,
                    'by' => $log->employee?->full_name ?? 'System',
                    'at' => $log->created_at?->toDateTimeString(),
                    'details' => $log->after,
                ])->all(),
        ]]);
    }

    /**
     * GET phone-bond/customers/{customer}/devices - the customer's phones for the loan application: enrolled phones that
     * may secure a new loan (Continue With Existing Device) and registrations still pending.
     */
    public function customerDevices(Customer $customer): JsonResponse
    {
        $this->authorizeAny('phone_bond.manage', 'loans.apply');
        $this->assertBranchAccessible((int) $customer->branch_id);
        abort_unless((int) $customer->company_id === (int) $this->currentEmployee()->company_id, 404);

        $pending = PhoneBondDevice::where('customer_id', $customer->id)->where('status', PhoneBondDevice::PENDING)->latest('id')->get();

        return response()->json(['data' => [
            'reusable' => $this->phoneBond->reusableDevices($customer)->map(fn (PhoneBondDevice $device): array => $this->present($device))->values()->all(),
            'pending' => $pending->map(fn (PhoneBondDevice $device): array => $this->present($device, true))->values()->all(),
            'unavailable' => PhoneBondDevice::where('customer_id', $customer->id)->whereIn('status', [PhoneBondDevice::ACTIVE, PhoneBondDevice::DONE])
                ->get()->reject(fn (PhoneBondDevice $device): bool => $this->phoneBond->reusableDevices($customer)->contains('id', $device->id))
                ->map(fn (PhoneBondDevice $device): array => $this->present($device))->values()->all(),
        ]]);
    }

    /**
     * POST phone-bond/registrations - register a new phone with Linda for a customer (kept as a draft when Linda is down).
     */
    public function register(Request $request): JsonResponse
    {
        $this->authorizeAny('phone_bond.manage');
        $validated = $request->validate([
            'customer_id' => ['required', 'integer'],
            'imei' => ['required', 'string', 'regex:/^\d{15}$/'],
            'make' => ['required', 'string', 'max:60'],
            'model' => ['required', 'string', 'max:60'],
            'serial_number' => ['nullable', 'string', 'max:64'],
            'application' => ['nullable', 'array'],
        ], ['imei.regex' => 'The IMEI must be exactly 15 digits.']);
        $customer = Customer::where('company_id', $this->currentEmployee()->company_id)->findOrFail($validated['customer_id']);
        $this->assertBranchAccessible((int) $customer->branch_id);

        $device = $this->phoneBond->startRegistration($customer, $validated, $validated['application'] ?? null, $this->currentEmployee());

        return response()->json(['message' => $this->registrationMessage($device), 'data' => $this->present($device, true)], 201);
    }

    /**
     * POST phone-bond/devices/{device}/register - retry a registration Linda could not take.
     */
    public function retryRegistration(PhoneBondDevice $device): JsonResponse
    {
        $this->authorizeAny('phone_bond.manage');
        $this->assertVisible($device);
        $device = $this->phoneBond->registerWithLinda($device, $this->currentEmployee());

        return response()->json(['message' => $this->registrationMessage($device), 'data' => $this->present($device, true)]);
    }

    /**
     * POST phone-bond/devices/{device}/enrollment - ask Linda whether the phone finished enrollment (fresh QR if expired).
     */
    public function checkEnrollment(PhoneBondDevice $device): JsonResponse
    {
        $this->authorizeAny('phone_bond.manage');
        $this->assertVisible($device);
        $device = $this->phoneBond->checkEnrollment($device, $this->currentEmployee());

        return response()->json(['message' => $device->isEnrolled() ? 'Linda confirms the phone is enrolled.' : $this->registrationMessage($device), 'data' => $this->present($device, true)]);
    }

    /**
     * POST phone-bond/devices/{device}/inspection - the physical inspection of a phone that secured an earlier loan.
     */
    public function inspect(Request $request, PhoneBondDevice $device): JsonResponse
    {
        $this->authorizeAny('phone_bond.manage');
        $this->assertVisible($device);
        $validated = $request->validate(['condition' => ['required', Rule::in(['good', 'damaged'])], 'note' => ['nullable', 'string', 'max:500']]);

        return response()->json(['message' => 'Inspection recorded', 'data' => $this->present($this->phoneBond->inspect($device, $validated['condition'], $validated['note'] ?? null, $this->currentEmployee()))]);
    }

    /**
     * POST phone-bond/devices/{device}/lock | unlock - manual action with a reason; pending until the phone confirms.
     */
    public function action(Request $request, PhoneBondDevice $device, string $action): JsonResponse
    {
        $this->authorizeAny('phone_bond.lock');
        $this->assertVisible($device);
        abort_unless(in_array($action, PhoneBondLocks::ACTIONS, true), 404);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        $lockRequest = $this->locks->request($device, $action, 'manual', $validated['reason'], $this->currentEmployee());
        $message = match ($lockRequest->status) {
            PhoneBondLockRequest::CONFIRMED => 'The phone confirmed the '.$action.'.',
            PhoneBondLockRequest::PENDING => 'Linda accepted the '.$action.'; the device shows '.ucfirst($action).' Pending until the phone confirms.',
            default => 'Linda did not '.$action.' the device: '.($lockRequest->provider_message ?? 'no answer').'.',
        };

        return response()->json(['message' => $message, 'data' => $this->phoneBond->presentRequest($lockRequest)], $lockRequest->status === PhoneBondLockRequest::FAILED ? 422 : 200);
    }

    /**
     * POST phone-bond/requests/{lockRequest}/confirm - record the reference of a lock / unlock done in the Linda portal.
     */
    public function confirm(Request $request, PhoneBondLockRequest $lockRequest): JsonResponse
    {
        $this->authorizeAny('phone_bond.lock');
        $this->assertVisible($lockRequest->device);
        $validated = $request->validate(['reference' => ['required', 'string', 'max:100']]);

        return response()->json(['message' => 'Linda confirmation recorded', 'data' => $this->phoneBond->presentRequest($this->locks->confirmFromPortal($lockRequest, $validated['reference'], $this->currentEmployee()))]);
    }

    /**
     * POST phone-bond/devices/{device}/release - record that the phone was released in the Linda portal.
     */
    public function release(Request $request, PhoneBondDevice $device): JsonResponse
    {
        $this->authorizeAny('phone_bond.release');
        $this->assertVisible($device);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'released_in_linda' => ['accepted'],
        ], ['released_in_linda.accepted' => 'Release the device in the Linda portal first, then confirm it here.']);

        return response()->json(['message' => 'Device released', 'data' => $this->present($this->phoneBond->release($device, $validated['reason'], $this->currentEmployee()))]);
    }

    /**
     * POST phone-bond/devices/{device}/incidents - report the phone lost, stolen or damaged.
     */
    public function reportIncident(Request $request, PhoneBondDevice $device): JsonResponse
    {
        $this->authorizeAny('phone_bond.manage', 'phone_bond.replace_request');
        $this->assertVisible($device);
        $validated = $request->validate([
            'type' => ['required', Rule::in(PhoneBondIncident::TYPES)],
            'details' => ['required', 'string', 'max:1000'],
            'occurred_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);

        $this->phoneBond->reportIncident($device, $validated['type'], $validated['details'], $validated['occurred_on'] ?? null, $this->currentEmployee());

        return response()->json(['message' => 'Incident recorded. The loan and its balance are unchanged.', 'data' => $this->present($device->fresh())], 201);
    }

    /**
     * POST phone-bond/devices/{device}/replacements - the Loan Officer asks to replace the phone with an enrolled new one.
     */
    public function requestReplacement(Request $request, PhoneBondDevice $device): JsonResponse
    {
        $this->authorizeAny('phone_bond.replace_request');
        $this->assertVisible($device);
        $validated = $request->validate([
            'new_device_id' => ['required', 'integer'],
            'reason_type' => ['required', Rule::in(PhoneBondReplacement::REASON_TYPES)],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $new = PhoneBondDevice::where('company_id', $device->company_id)->findOrFail($validated['new_device_id']);
        $this->assertVisible($new);

        $replacement = $this->phoneBond->requestReplacement($device, $new, $validated['reason_type'], $validated['reason'], $this->currentEmployee());

        return response()->json(['message' => 'Replacement request submitted for approval', 'data' => $this->presentReplacement($replacement->load(['oldDevice', 'newDevice', 'loan', 'requester']))], 201);
    }

    /**
     * GET phone-bond/replacements - replacement requests in scope (pending first).
     */
    public function replacements(Request $request): JsonResponse
    {
        $this->authorizeAny(...self::VIEW);
        $replacements = $this->scoped(PhoneBondReplacement::query())
            ->when($request->filled('status') && $request->input('status') !== 'all', fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->with(['oldDevice:id,imei,customer_id', 'oldDevice.customer:id,first_name,middle_name,last_name', 'newDevice:id,imei,brand,model', 'loan:id,loan_number', 'requester:id,first_name,last_name', 'decider:id,first_name,last_name'])
            ->orderByRaw("status = 'pending' desc")->latest('id')->limit(500)->get();

        return response()->json(['data' => $replacements->map(fn (PhoneBondReplacement $replacement): array => $this->presentReplacement($replacement))->all()]);
    }

    /**
     * POST phone-bond/replacements/{replacement}/approve | reject.
     */
    public function decideReplacement(Request $request, PhoneBondReplacement $replacement, string $decision): JsonResponse
    {
        $this->authorizeAny('phone_bond.replace_approve');
        abort_unless($this->scoped(PhoneBondReplacement::query())->whereKey($replacement->id)->exists(), 404);
        $validated = $request->validate(['note' => [$decision === 'reject' ? 'required' : 'nullable', 'string', 'max:500']]);

        $replacement = $this->phoneBond->decideReplacement($replacement, $decision === 'approve', $validated['note'] ?? null, $this->currentEmployee());

        return response()->json(['message' => $decision === 'approve' ? 'Replacement approved: the new phone now secures the loan.' : 'Replacement rejected',
            'data' => $this->presentReplacement($replacement->load(['oldDevice', 'newDevice', 'loan', 'requester', 'decider']))]);
    }

    /**
     * GET phone-bond/report - device counts and lock / unlock history for the branch scope and period.
     */
    public function report(Request $request): JsonResponse
    {
        $this->authorizeAny(...self::VIEW);
        [$branchIds, $from, $to] = $this->scope($request);

        return response()->json(['data' => $this->phoneBond->report($this->currentEmployee()->company_id, $branchIds, $from, $to)
            + ['filter' => ['branch_id' => $request->input('branch_id', 'all'), 'from' => $from->toDateString(), 'to' => $to->toDateString()]]]);
    }

    /**
     * GET phone-bond/payments - eSIMU → Payments (existing payment records of eSIMU loans only).
     */
    public function payments(Request $request, PhoneBondPayments $payments): JsonResponse
    {
        $this->authorizeAny(...self::VIEW);
        [$branchIds, $from, $to] = $this->scope($request);

        return response()->json($payments->dashboard($this->currentEmployee()->company_id, $branchIds, $from, $to, [
            'search' => $request->input('search'),
            'status' => $request->input('status'),
            'page' => $request->integer('page', 1),
            'per_page' => $request->integer('per_page', 20),
        ]) + ['filter' => ['branch_id' => $request->input('branch_id', 'all'), 'from' => $from->toDateString(), 'to' => $to->toDateString()]]);
    }

    /**
     * @return array{0: list<int>|null, 1: CarbonImmutable, 2: CarbonImmutable}
     */
    private function scope(Request $request): array
    {
        $branchIds = app(AccessControl::class)->branchIds($this->currentEmployee());
        if ($request->filled('branch_id') && $request->input('branch_id') !== 'all') {
            $this->assertBranchAccessible($request->integer('branch_id'));
            $branchIds = [$request->integer('branch_id')];
        }
        // No dates = All Time (the period filter sends none for it).
        $from = $request->filled('from') ? CarbonImmutable::parse($request->string('from')->toString()) : CarbonImmutable::create(2000);
        $to = $request->filled('to') ? CarbonImmutable::parse($request->string('to')->toString()) : PhoneBond::today()->addYears(50);

        return [$branchIds, $from, $to];
    }

    private function registrationMessage(PhoneBondDevice $device): string
    {
        return match (true) {
            $device->isEnrolled() => 'Linda confirms the phone is enrolled.',
            $device->enrollment_status === PhoneBondDevice::NOT_REGISTERED => 'The device could not be registered with Linda ('.($device->registration_error ?? 'no answer').'). The registration and application are saved as Pending Registration; retry when Linda is available.',
            default => 'Registered with Linda. Ask the customer to scan the QR code on the factory-reset phone, then check enrollment.',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PhoneBondDevice $device, bool $withEnrollment = false): array
    {
        return [
            'id' => $device->id,
            'imei' => $device->imei,
            'serial_number' => $device->serial_number,
            'make' => $device->brand,
            'brand' => $device->brand,
            'model' => $device->model,
            'phone_number' => $device->phone_number,
            'customer_id' => $device->customer_id,
            'customer' => $device->customer?->full_name,
            'customer_phone' => $device->customer?->phone,
            'branch_id' => $device->branch_id,
            'branch' => $device->branch?->name,
            'loan_id' => $device->loan_id,
            'loan_number' => $device->loan?->loan_number,
            'loan_status' => $device->loan?->status?->label(),
            'linda_device_id' => $device->linda_device_id,
            'linda_status' => $device->linda_status,
            'enrollment_status' => $device->enrollment_status,
            'enrolled_at' => $device->enrolled_at?->toDateTimeString(),
            'registration_error' => $device->registration_error,
            'sim_protection' => $device->sim_protection,
            'last_seen_at' => $device->last_seen_at?->toDateTimeString(),
            'status' => $device->status,
            'condition' => $device->condition,
            'inspected_at' => $device->inspected_at?->toDateTimeString(),
            'lock_state' => $device->lock_state,
            'closed_reason' => $device->closed_reason,
            'closed_at' => $device->closed_at?->toDateTimeString(),
            'registered_at' => $device->created_at?->toDateTimeString(),
        ] + ($withEnrollment ? [
            'qr_svg' => $this->phoneBond->qrSvg($device),
            'enrollment_expires_at' => $device->enrollment_expires_at?->toDateTimeString(),
            'application' => $device->application_draft,
        ] : []);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentReplacement(PhoneBondReplacement $replacement): array
    {
        return [
            'id' => $replacement->id,
            'loan_id' => $replacement->loan_id,
            'loan_number' => $replacement->loan?->loan_number,
            'customer' => $replacement->oldDevice?->customer?->full_name,
            'old_device_id' => $replacement->old_device_id,
            'old_imei' => $replacement->oldDevice?->imei,
            'new_device_id' => $replacement->new_device_id,
            'new_imei' => $replacement->newDevice?->imei,
            'new_model' => trim(($replacement->newDevice?->brand ?? '').' '.($replacement->newDevice?->model ?? '')),
            'reason_type' => $replacement->reason_type,
            'reason' => $replacement->reason,
            'status' => $replacement->status,
            'requested_by_id' => $replacement->requested_by,
            'requested_by' => $replacement->requester?->full_name,
            'requested_at' => $replacement->created_at?->toDateTimeString(),
            'decided_by' => $replacement->decider?->full_name,
            'decided_at' => $replacement->decided_at?->toDateTimeString(),
            'decision_note' => $replacement->decision_note,
        ];
    }

    private function assertVisible(?PhoneBondDevice $device): void
    {
        abort_unless($device !== null && $this->scoped(PhoneBondDevice::query())->whereKey($device->id)->exists(), 404);
    }
}
