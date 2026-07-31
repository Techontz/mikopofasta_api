<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Actions\RecordPerformanceAction;
use App\Domain\Hr\Actions\RegisterStaffAction;
use App\Domain\Hr\Actions\StaffAdvanceAction;
use App\Domain\Hr\DTOs\RegisterStaffData;
use App\Domain\Hr\Enums\PerformanceRating;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\DecideStaffAdvanceRequest;
use App\Http\Requests\Hr\IndexStaffRequest;
use App\Http\Requests\Hr\RecordPerformanceRequest;
use App\Http\Requests\Hr\RegisterStaffRequest;
use App\Http\Requests\Hr\StaffAdvanceRequest;
use App\Http\Requests\Hr\UpdateStaffRequest;
use App\Http\Resources\StaffAdvanceDetailResource;
use App\Http\Resources\StaffAdvanceResource;
use App\Http\Resources\StaffLoanResource;
use App\Http\Resources\StaffPerformanceRecordResource;
use App\Http\Resources\StaffProfileResource;
use App\Models\StaffAdvance;
use App\Models\StaffLoan;
use App\Models\StaffPerformanceRecord;
use App\Models\StaffProfile;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff, advances and performance — §15.5.
 *
 * HR is an HQ function (§14 scopes it to all branches), so unlike customers
 * and loans nothing here is branch-scoped. A company has one personnel record
 * per employee, not one per branch.
 */
final class StaffController extends Controller
{
    /**
     * GET /api/v1/staff
     */
    public function index(IndexStaffRequest $request): JsonResponse
    {
        $this->authorize('viewAny', StaffProfile::class);

        $filters = $request->validated();

        $query = StaffProfile::query()
            ->with(['user.role', 'branch'])
            ->when(
                isset($filters['search']) && $filters['search'] !== '',
                fn ($q) => $q->where(function ($q) use ($filters): void {
                    $term = '%'.$filters['search'].'%';
                    $q->where('employee_number', 'like', $term)
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $term)
                            ->orWhere('phone', 'like', $term));
                }),
            )
            ->when(! empty($filters['employment_status']), fn ($q) => $q->whereIn('employment_status', $filters['employment_status']))
            ->when(isset($filters['branch_id']), fn ($q) => $q->where('branch_id', $filters['branch_id']))
            ->when(isset($filters['commission_eligible']), fn ($q) => $q->where('commission_eligible', $filters['commission_eligible']))
            ->orderBy('employee_number');

        return ApiResponse::paginated(
            $query->paginate(ApiResponse::perPage($request->query('per_page')))->withQueryString(),
            StaffProfileResource::class,
        );
    }

    /**
     * POST /api/v1/staff — §15.5, HR registers staff.
     */
    public function store(RegisterStaffRequest $request, RegisterStaffAction $action): JsonResponse
    {
        $this->authorize('manage', StaffProfile::class);

        $profile = $action->handle(RegisterStaffData::fromArray($request->validated()), $this->actor($request));

        return ApiResponse::data(
            new StaffProfileResource($profile->load(['user.role', 'branch', 'bankDetail'])),
            status: Response::HTTP_CREATED,
        );
    }

    /**
     * GET /api/v1/staff/{staffProfile}
     */
    public function show(Request $request, StaffProfile $staffProfile): JsonResponse
    {
        $this->authorize('view', $staffProfile);

        return ApiResponse::data(
            new StaffProfileResource($staffProfile->load(['user.role', 'branch', 'zone', 'bankDetail'])),
            [
                // The staff detail page shows all three alongside the profile.
                'loans' => StaffLoanResource::collection($staffProfile->loans()->latest('id')->get()),
                'advances' => StaffAdvanceResource::collection($staffProfile->advances()->latest('id')->get()),
                'performance' => StaffPerformanceRecordResource::collection(
                    $staffProfile->performanceRecords()->latest('period')->get(),
                ),
            ],
        );
    }

    /**
     * PUT /api/v1/staff/{staffProfile}
     */
    public function update(UpdateStaffRequest $request, StaffProfile $staffProfile): JsonResponse
    {
        $this->authorize('manage', StaffProfile::class);

        $data = $request->validated();

        /*
         * Three writes — the profile, the bank details and the audit row — so
         * one transaction. Without it a failure on the bank details would
         * leave a salary already changed and no audit row recording that it
         * was, which is the one combination a financial system must not
         * produce. Every other write path in the codebase is transactional for
         * the same reason.
         */
        DB::transaction(function () use ($staffProfile, $data, $request): void {
            $staffProfile->update(array_filter([
                'base_salary' => isset($data['baseSalary']) ? Money::of((string) $data['baseSalary'])->toDecimalString() : null,
                'commission_eligible' => $data['commissionEligible'] ?? null,
                'payment_method' => $data['paymentMethod'] ?? null,
                'employment_status' => $data['employmentStatus'] ?? null,
            ], static fn (mixed $v): bool => $v !== null));

            if (isset($data['bankName'], $data['bankAccountNumber'])) {
                $staffProfile->bankDetail()->updateOrCreate([], [
                    'bank_name' => $data['bankName'],
                    'account_number' => $data['bankAccountNumber'],
                ]);
            }

            $this->audit(AuditAction::StaffUpdated, $staffProfile, $data, $request);
        });

        return ApiResponse::data(
            new StaffProfileResource($staffProfile->fresh(['user.role', 'branch', 'bankDetail'])),
        );
    }

    /**
     * GET /api/v1/staff/advances
     */
    public function advances(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StaffProfile::class);

        $advances = StaffAdvance::query()
            ->with('staffProfile.user')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest('id')
            ->get();

        return ApiResponse::data(StaffAdvanceResource::collection($advances), ['total' => $advances->count()]);
    }

    /**
     * GET /api/v1/staff/loans
     *
     * Read-only: no endpoint creates a staff loan, because neither §11 nor the
     * frontend defines the terms one would be created on. See the Phase 7
     * notes in the README.
     */
    public function loans(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StaffProfile::class);

        $loans = StaffLoan::query()->with('staffProfile.user')->latest('id')->get();

        return ApiResponse::data(StaffLoanResource::collection($loans), ['total' => $loans->count()]);
    }

    /**
     * POST /api/v1/staff/advance/request — §15.5.
     */
    public function requestAdvance(StaffAdvanceRequest $request, StaffAdvanceAction $action): JsonResponse
    {
        $this->authorize('manage', StaffProfile::class);

        $staff = StaffProfile::query()->findOrFail($request->validated('staffProfileId'));

        $advance = $action->request(
            $staff,
            Money::of((string) $request->validated('amount')),
            $this->actor($request),
        );

        return ApiResponse::data(
            new StaffAdvanceDetailResource($advance->load(StaffAdvance::LIST_RELATIONS)),
            status: Response::HTTP_CREATED,
        );
    }

    /**
     * POST /api/v1/staff/advance/approve — §15.5, HR's decision.
     */
    public function approveAdvance(DecideStaffAdvanceRequest $request, StaffAdvanceAction $action): JsonResponse
    {
        $this->authorize('manage', StaffProfile::class);

        $advance = $action->approve($this->advance($request), $this->actor($request));

        return ApiResponse::data(new StaffAdvanceDetailResource($advance->load(StaffAdvance::LIST_RELATIONS)));
    }

    /**
     * POST /api/v1/staff/advance/reject
     */
    public function rejectAdvance(DecideStaffAdvanceRequest $request, StaffAdvanceAction $action): JsonResponse
    {
        $this->authorize('manage', StaffProfile::class);

        $advance = $action->reject($this->advance($request), $this->actor($request));

        return ApiResponse::data(new StaffAdvanceDetailResource($advance->load(StaffAdvance::LIST_RELATIONS)));
    }

    /**
     * POST /api/v1/staff/advance/disburse — Finance only.
     *
     * §11: "disbursement (Finance only, never HR)". The authorization check is
     * `disburseAdvance`, which requires `payroll.finalize` — the Finance
     * money-movement grant — and not `hr.manage`.
     */
    public function disburseAdvance(DecideStaffAdvanceRequest $request, StaffAdvanceAction $action): JsonResponse
    {
        $this->authorize('disburseAdvance', \App\Models\PayrollRun::class);

        $advance = $action->disburse($this->advance($request), $this->actor($request));

        return ApiResponse::data(new StaffAdvanceDetailResource($advance->load(StaffAdvance::LIST_RELATIONS)));
    }

    /**
     * GET /api/v1/staff/performance
     */
    public function performance(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StaffProfile::class);

        $records = StaffPerformanceRecord::query()
            ->with('staffProfile.user')
            ->when($request->filled('period'), fn ($q) => $q->where('period', $request->string('period')))
            ->latest('period')
            ->get();

        return ApiResponse::data(
            StaffPerformanceRecordResource::collection($records),
            ['total' => $records->count()],
        );
    }

    /**
     * POST /api/v1/staff/performance — §15.5.
     */
    public function recordPerformance(RecordPerformanceRequest $request, RecordPerformanceAction $action): JsonResponse
    {
        $this->authorize('recordPerformance', StaffProfile::class);

        $data = $request->validated();
        $staff = StaffProfile::query()->findOrFail($data['staffProfileId']);

        $record = $action->handle(
            staff: $staff,
            period: (string) $data['period'],
            targets: $data['targets'],
            achieved: $data['achieved'],
            rating: isset($data['rating']) ? PerformanceRating::from((string) $data['rating']) : null,
            actor: $this->actor($request),
        );

        return ApiResponse::data(
            new StaffPerformanceRecordResource($record->load('staffProfile.user')),
            status: Response::HTTP_CREATED,
        );
    }

    private function advance(DecideStaffAdvanceRequest $request): StaffAdvance
    {
        return StaffAdvance::query()
            ->with('staffProfile.user')
            ->findOrFail($request->validated('advanceId'));
    }

    /**
     * @param array<string, mixed> $after
     */
    private function audit(AuditAction $action, StaffProfile $staff, array $after, Request $request): void
    {
        app(\App\Services\AuditLogger::class)->log($action, $staff, after: $after, actor: $this->actor($request));
    }
}
