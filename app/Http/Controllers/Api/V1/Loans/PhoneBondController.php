<?php

namespace App\Http\Controllers\Api\V1\Loans;

use App\Models\Loan;
use App\Models\PhoneBondDevice;
use App\Models\PhoneBondLockRequest;
use App\Services\AccessControl;
use App\Services\PhoneBond;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phone Bond → Devices / Manage Device / Lock-Unlock / reports ({@see PhoneBond}), in the employee's branch scope.
 */
class PhoneBondController extends LoanApiController
{
    public function __construct(private PhoneBond $phoneBond) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny('phone_bond.manage', 'phone_bond.lock', 'loans.view');

        $devices = $this->scoped(PhoneBondDevice::query())
            ->with(['customer:id,first_name,middle_name,last_name,phone', 'branch:id,name', 'loan:id,loan_number,status'])
            ->when($request->filled('status') && $request->input('status') !== 'all', fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('lock_state') && $request->input('lock_state') !== 'all', fn ($query) => $query->where('lock_state', $request->string('lock_state')->toString()))
            ->latest('id');
        $this->applyFilters($devices, $request);

        return response()->json(['data' => $devices->get()->map(fn (PhoneBondDevice $device): array => $this->present($device))->all()]);
    }

    public function show(PhoneBondDevice $device): JsonResponse
    {
        $this->authorizeAny('phone_bond.manage', 'phone_bond.lock', 'loans.view');
        $this->assertVisible($device);

        return response()->json(['data' => $this->present($device->load(['customer', 'branch', 'loan'])) + [
            'history' => $device->requests()->with('requester:id,first_name,last_name')->latest('id')->get()
                ->map(fn (PhoneBondLockRequest $request): array => $this->phoneBond->presentRequest($request->setRelation('device', $device)))->all(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAny('phone_bond.manage');
        $validated = $request->validate([
            'loan_id' => ['required', 'integer'],
            'imei' => ['required', 'string', 'regex:/^\d{14,17}$/'],
            'serial_number' => ['nullable', 'string', 'max:64'],
            'brand' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'linda_device_id' => ['nullable', 'string', 'max:100'],
            'sim_protection' => ['nullable', 'boolean'],
        ]);
        $loan = Loan::where('company_id', $this->currentEmployee()->company_id)->findOrFail($validated['loan_id']);
        $this->ensureVisible($loan);
        unset($validated['loan_id']);

        return response()->json(['message' => 'Device registered', 'data' => $this->present($this->phoneBond->register($loan, $validated, $this->currentEmployee()))], 201);
    }

    /**
     * POST phone-bond/devices/{device}/lock | unlock — manual action with a reason.
     */
    public function action(Request $request, PhoneBondDevice $device, string $action): JsonResponse
    {
        $this->authorizeAny('phone_bond.lock');
        $this->assertVisible($device);
        abort_unless(in_array($action, ['lock', 'unlock'], true), 404);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $lockRequest = $this->phoneBond->request($device, $action, 'manual', $validated['reason'], $this->currentEmployee());
        $message = match ($lockRequest->status) {
            PhoneBondLockRequest::CONFIRMED => 'Linda confirmed the '.$action.'.',
            PhoneBondLockRequest::PENDING => 'Linda accepted the request; it is not '.$action.'ed until Linda confirms.',
            default => 'Linda did not '.$action.' the device: '.($lockRequest->provider_message ?? 'no answer').'.',
        };

        return response()->json(['message' => $message, 'data' => $this->phoneBond->presentRequest($lockRequest)], $lockRequest->status === PhoneBondLockRequest::FAILED ? 422 : 200);
    }

    /**
     * POST phone-bond/requests/{lockRequest}/confirm — record Linda's confirmation reference.
     */
    public function confirm(Request $request, PhoneBondLockRequest $lockRequest): JsonResponse
    {
        $this->authorizeAny('phone_bond.lock');
        $this->assertVisible($lockRequest->device);
        $validated = $request->validate(['reference' => ['required', 'string', 'max:100']]);

        return response()->json(['message' => 'Linda confirmation recorded', 'data' => $this->phoneBond->presentRequest($this->phoneBond->confirm($lockRequest, $validated['reference'], $this->currentEmployee()))]);
    }

    public function close(Request $request, PhoneBondDevice $device): JsonResponse
    {
        $this->authorizeAny('phone_bond.manage');
        $this->assertVisible($device);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json(['message' => 'Device closed', 'data' => $this->present($this->phoneBond->close($device, $validated['reason'], $this->currentEmployee()))]);
    }

    public function report(Request $request): JsonResponse
    {
        $this->authorizeAny('phone_bond.manage', 'phone_bond.lock', 'loans.view');
        $branchIds = app(AccessControl::class)->branchIds($this->currentEmployee());
        if ($request->filled('branch_id') && $request->input('branch_id') !== 'all') {
            $this->assertBranchAccessible($request->integer('branch_id'));
            $branchIds = [$request->integer('branch_id')];
        }
        $from = $request->filled('from') ? CarbonImmutable::parse($request->string('from')->toString()) : CarbonImmutable::today()->startOfMonth();
        $to = $request->filled('to') ? CarbonImmutable::parse($request->string('to')->toString()) : CarbonImmutable::today()->endOfMonth();

        return response()->json(['data' => $this->phoneBond->report($this->currentEmployee()->company_id, $branchIds, $from, $to)
            + ['filter' => ['branch_id' => $request->input('branch_id', 'all'), 'from' => $from->toDateString(), 'to' => $to->toDateString()]]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PhoneBondDevice $device): array
    {
        return [
            'id' => $device->id,
            'imei' => $device->imei,
            'serial_number' => $device->serial_number,
            'brand' => $device->brand,
            'model' => $device->model,
            'phone_number' => $device->phone_number,
            'customer_id' => $device->customer_id,
            'customer' => $device->customer?->full_name,
            'branch' => $device->branch?->name,
            'loan_id' => $device->loan_id,
            'loan_number' => $device->loan?->loan_number,
            'loan_status' => $device->loan?->status?->label(),
            'linda_device_id' => $device->linda_device_id,
            'enrollment_status' => $device->enrollment_status,
            'sim_protection' => $device->sim_protection,
            'last_seen_at' => $device->last_seen_at?->toDateTimeString(),
            'status' => $device->status,
            'lock_state' => $device->lock_state,
            'closed_reason' => $device->closed_reason,
            'closed_at' => $device->closed_at?->toDateTimeString(),
        ];
    }

    private function assertVisible(?PhoneBondDevice $device): void
    {
        abort_unless($device !== null && $this->scoped(PhoneBondDevice::query())->whereKey($device->id)->exists(), 404);
    }
}
