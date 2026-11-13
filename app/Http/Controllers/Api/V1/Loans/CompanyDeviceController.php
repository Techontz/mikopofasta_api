<?php

namespace App\Http\Controllers\Api\V1\Loans;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\AuditLog;
use App\Models\PhoneBondDevice;
use App\Models\PhoneBondIncident;
use App\Models\PhoneBondLockRequest;
use App\Services\PhoneBond;
use App\Services\PhoneBondLocks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * eSIMU → Company Devices: company-owned smartphones under Linda Device Lock, in the employee's branch scope. They reuse
 * the eSIMU Linda registration, QR enrollment, lock / unlock commands, webhooks, incidents and audit, but never a loan.
 * Privileges (assigned per user): register_company_device and manage_company_device_lock_unlock.
 */
class CompanyDeviceController extends ApiController
{
    /**
     * @var list<string>
     */
    private const ANY = ['register_company_device', 'manage_company_device_lock_unlock'];

    public function __construct(
        private PhoneBond $phoneBond,
        private PhoneBondLocks $locks,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny(...self::ANY);

        $devices = $this->scoped(PhoneBondDevice::query()->companyDevices())
            ->with(['branch:id,name'])
            ->when($request->filled('status') && $request->input('status') !== 'all', fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $like = '%'.trim($request->string('search')->toString()).'%';
                $query->where(fn ($inner) => $inner->where('imei', 'like', $like)->orWhere('department', 'like', $like)->orWhere('model', 'like', $like)->orWhere('serial_number', 'like', $like));
            })
            ->latest('id');
        $this->applyFilters($devices, $request);

        return response()->json(['data' => $devices->limit(1000)->get()->map(fn (PhoneBondDevice $device): array => $this->present($device))->all()]);
    }

    public function show(PhoneBondDevice $device): JsonResponse
    {
        $this->authorizeAny(...self::ANY);
        $this->assertVisible($device);
        $device->load(['branch', 'registeredBy']);

        return response()->json(['data' => $this->present($device, true) + [
            'history' => $device->requests()->with('requester:id,first_name,last_name')->latest('id')->get()
                ->map(fn (PhoneBondLockRequest $request): array => $this->phoneBond->presentRequest($request->setRelation('device', $device)))->all(),
            'incidents' => $device->incidents()->with('reporter:id,first_name,last_name')->latest('id')->get()->map(fn (PhoneBondIncident $incident): array => [
                'id' => $incident->id,
                'type' => $incident->type,
                'details' => $incident->details,
                'occurred_on' => $incident->occurred_on?->toDateString(),
                'reported_by' => $incident->reporter?->full_name,
                'reported_at' => $incident->created_at?->toDateTimeString(),
            ])->all(),
            'audit' => AuditLog::where('auditable_type', $device->getMorphClass())->where('auditable_id', $device->id)->where('action', 'like', 'PhoneBond.%')
                ->with('employee:id,first_name,last_name')->latest('id')->limit(300)->get()->map(fn (AuditLog $log): array => [
                    'action' => $log->action,
                    'by' => $log->employee?->full_name ?? 'System',
                    'at' => $log->created_at?->toDateTimeString(),
                    'details' => $log->after,
                ])->all(),
        ]]);
    }

    /**
     * POST company-devices - Register Company Device: sent to Linda, QR returned; kept pending when Linda is down.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeAny('register_company_device');
        $validated = $request->validate([
            'imei' => ['required', 'string', 'regex:/^\d{15}$/'],
            'make' => ['required', 'string', 'max:60'],
            'model' => ['required', 'string', 'max:60'],
            'serial_number' => ['nullable', 'string', 'max:64'],
            'department' => ['required', 'string', 'max:100'],
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('company_id', $this->currentEmployee()->company_id)],
        ], ['imei.regex' => 'The IMEI must be exactly 15 digits.']);
        $this->assertBranchAccessible((int) $validated['branch_id']);

        $device = $this->phoneBond->registerCompanyDevice($this->currentEmployee()->company_id, $validated, $this->currentEmployee());

        return response()->json(['message' => $this->registrationMessage($device), 'data' => $this->present($device->load('branch'), true)], 201);
    }

    public function retryRegistration(PhoneBondDevice $device): JsonResponse
    {
        $this->authorizeAny('register_company_device');
        $this->assertVisible($device);
        $device = $this->phoneBond->registerWithLinda($device, $this->currentEmployee());

        return response()->json(['message' => $this->registrationMessage($device), 'data' => $this->present($device->load('branch'), true)]);
    }

    public function checkEnrollment(PhoneBondDevice $device): JsonResponse
    {
        $this->authorizeAny('register_company_device');
        $this->assertVisible($device);
        $device = $this->phoneBond->checkEnrollment($device, $this->currentEmployee());

        return response()->json(['message' => $this->registrationMessage($device), 'data' => $this->present($device->load('branch'), true)]);
    }

    /**
     * POST company-devices/{device}/lock | unlock - manual, with a reason; pending until the phone confirms.
     */
    public function action(Request $request, PhoneBondDevice $device, string $action): JsonResponse
    {
        $this->authorizeAny('manage_company_device_lock_unlock');
        $this->assertVisible($device);
        abort_unless(in_array($action, PhoneBondLocks::ACTIONS, true), 404);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        $lockRequest = $this->locks->request($device, $action, 'manual', $validated['reason'], $this->currentEmployee());
        $message = match ($lockRequest->status) {
            PhoneBondLockRequest::CONFIRMED => 'The phone confirmed the '.$action.'.',
            PhoneBondLockRequest::PENDING => 'Linda accepted the '.$action.'; the device shows '.ucfirst($action).' Pending until the phone confirms.',
            default => 'Command failed: '.($lockRequest->provider_message ?? 'no answer from Linda').'.',
        };

        return response()->json(['message' => $message, 'data' => $this->phoneBond->presentRequest($lockRequest)], $lockRequest->status === PhoneBondLockRequest::FAILED ? 422 : 200);
    }

    /**
     * POST company-devices/{device}/incidents - report the phone lost or stolen (the record and its history are kept).
     */
    public function reportIncident(Request $request, PhoneBondDevice $device): JsonResponse
    {
        $this->authorizeAny(...self::ANY);
        $this->assertVisible($device);
        $validated = $request->validate([
            'type' => ['required', Rule::in(['lost', 'stolen'])],
            'details' => ['required', 'string', 'max:1000'],
            'occurred_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);

        $this->phoneBond->reportIncident($device, $validated['type'], $validated['details'], $validated['occurred_on'] ?? null, $this->currentEmployee());

        return response()->json(['message' => 'Incident recorded. Lock the device if needed.', 'data' => $this->present($device->fresh(['branch']))], 201);
    }

    private function registrationMessage(PhoneBondDevice $device): string
    {
        return match (true) {
            $device->isEnrolled() => 'Linda confirms the device is enrolled.',
            $device->enrollment_status === PhoneBondDevice::NOT_REGISTERED => 'The device could not be registered with Linda ('.($device->registration_error ?? 'no answer').'). It is saved; retry when Linda is available.',
            default => 'Registered with Linda. Scan the QR code on the factory-reset phone, then check enrollment.',
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
            'make' => $device->brand,
            'model' => $device->model,
            'serial_number' => $device->serial_number,
            'department' => $device->department,
            'branch_id' => $device->branch_id,
            'branch' => $device->branch?->name,
            'registered_at' => $device->created_at?->toDateTimeString(),
            'registered_by' => $device->relationLoaded('registeredBy') ? $device->registeredBy?->full_name : null,
            'linda_device_id' => $device->linda_device_id,
            'linda_status' => $device->linda_status,
            'enrollment_status' => $device->enrollment_status,
            'enrolled_at' => $device->enrolled_at?->toDateTimeString(),
            'registration_error' => $device->registration_error,
            'last_seen_at' => $device->last_seen_at?->toDateTimeString(),
            'lock_state' => $device->lock_state,
            'status' => $device->status,
            'condition' => $device->condition,
        ] + ($withEnrollment ? [
            'qr_svg' => $this->phoneBond->qrSvg($device),
            'enrollment_expires_at' => $device->enrollment_expires_at?->toDateTimeString(),
        ] : []);
    }

    private function assertVisible(?PhoneBondDevice $device): void
    {
        abort_unless($device !== null && $this->scoped(PhoneBondDevice::query()->companyDevices())->whereKey($device->id)->exists(), 404);
    }
}
