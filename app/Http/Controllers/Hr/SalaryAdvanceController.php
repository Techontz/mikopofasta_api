<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Actions\ManageSalaryAdvanceCategoryAction;
use App\Domain\Hr\DTOs\SalaryAdvanceCategoryData;
use App\Domain\Hr\Enums\DeductionType;
use App\Domain\Hr\Enums\StaffAdvanceStatus;
use App\Domain\Hr\Services\SalaryAdvanceCalculator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\IndexSalaryAdvanceRequest;
use App\Http\Requests\Hr\SalaryAdvanceCategoryRequest;
use App\Http\Resources\SalaryAdvanceCategoryResource;
use App\Http\Resources\SalaryAdvancePaymentResource;
use App\Http\Resources\StaffAdvanceDetailResource;
use App\Models\Deduction;
use App\Models\SalaryAdvanceCategory;
use App\Models\StaffAdvance;
use App\Models\StaffProfile;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The six Salary Advance screens — Category, Request, Approved, Active,
 * Repayment and Paid List.
 *
 * Five of them are one collection filtered by status, which is the same
 * decision the expense and bank queues make: an approved advance is not a
 * different record from a requested one, it is the same record later. The sixth
 * (Repayment) reads the payroll deductions that recovered them.
 *
 * The lifecycle itself lives on StaffController, where §15.5 put it, and is not
 * duplicated here — this controller reads and manages bands.
 *
 * See docs/modules/salary-advance.md.
 */
final class SalaryAdvanceController extends Controller
{
    public function __construct(private readonly SalaryAdvanceCalculator $calculator) {}

    // -----------------------------------------------------------------------
    // Categories — Salary Advance → Salary Advance Category
    // -----------------------------------------------------------------------

    /** GET /api/v1/salary-advance-categories */
    public function categories(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StaffProfile::class);

        $categories = SalaryAdvanceCategory::query()
            ->withCount('advances')
            // By band rather than by name: the list is a ladder, and reading it
            // in amount order is how anyone checks it has no gaps.
            ->orderBy('from_amount')
            ->get();

        return ApiResponse::data(SalaryAdvanceCategoryResource::collection($categories));
    }

    /** POST /api/v1/salary-advance-categories */
    public function storeCategory(
        SalaryAdvanceCategoryRequest $request,
        ManageSalaryAdvanceCategoryAction $action,
    ): JsonResponse {
        $this->authorize('manage', StaffProfile::class);

        $category = $action->create(
            SalaryAdvanceCategoryData::fromArray($request->validated()),
            $this->actor($request),
        );

        return ApiResponse::data(new SalaryAdvanceCategoryResource($category), status: Response::HTTP_CREATED);
    }

    /** PUT /api/v1/salary-advance-categories/{category} */
    public function updateCategory(
        SalaryAdvanceCategoryRequest $request,
        SalaryAdvanceCategory $category,
        ManageSalaryAdvanceCategoryAction $action,
    ): JsonResponse {
        $this->authorize('manage', StaffProfile::class);

        $updated = $action->update(
            $category,
            SalaryAdvanceCategoryData::fromArray($request->validated()),
            $this->actor($request),
        );

        return ApiResponse::data(new SalaryAdvanceCategoryResource($updated));
    }

    /** DELETE /api/v1/salary-advance-categories/{category} */
    public function destroyCategory(
        Request $request,
        SalaryAdvanceCategory $category,
        ManageSalaryAdvanceCategoryAction $action,
    ): JsonResponse {
        $this->authorize('manage', StaffProfile::class);

        $action->delete($category, $this->actor($request));

        return ApiResponse::data(['message' => "{$category->name} retired."]);
    }

    // -----------------------------------------------------------------------
    // The advance registers — Request / Approved / Active / Paid List
    // -----------------------------------------------------------------------

    /**
     * GET /api/v1/salary-advances?status=&branch_id=&search=…
     *
     * One endpoint, four screens. `status` picks which: absent for the request
     * queue's full view, `approved` for Approved, `active` for Active, `repaid`
     * for the Paid List.
     */
    public function index(IndexSalaryAdvanceRequest $request): JsonResponse
    {
        $this->authorize('viewAny', StaffProfile::class);

        $filters = $request->validated();

        $query = StaffAdvance::query()
            ->withListRelations()
            ->when(
                isset($filters['status']),
                fn (Builder $q) => $q->where(
                    'status',
                    StaffAdvanceStatus::fromFrontend((string) $filters['status']),
                ),
            )
            ->when(
                isset($filters['staff_profile_id']),
                fn (Builder $q) => $q->where('staff_profile_id', $filters['staff_profile_id']),
            )
            ->when(
                isset($filters['category_id']),
                fn (Builder $q) => $q->where('salary_advance_category_id', $filters['category_id']),
            )
            ->when(isset($filters['from']), fn (Builder $q) => $q->whereDate('requested_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn (Builder $q) => $q->whereDate('requested_at', '<=', $filters['to']))
            ->latest('id');

        /*
         * Branch and name reach through the staff profile: an advance belongs
         * to an employee, and the employee's posting is what "this branch's
         * advances" means.
         */
        $query = $this->filterThroughStaff($query, $filters);

        $advances = $query->paginate(ApiResponse::perPage($request->query('per_page')))->withQueryString();

        return ApiResponse::paginated(
            $advances,
            StaffAdvanceDetailResource::class,
            $this->totalsFor(collect($advances->items())),
        );
    }

    /**
     * GET /api/v1/salary-advances/repayments
     *
     * Salary Advance → Salary Advance Repayment, and the Paid List's detail.
     *
     * Read from `deductions`, not from `staff_advances.amount_recovered`: that
     * column is a running total and cannot say *when* any of it was taken, and
     * these screens have a date column. The deduction rows are also what the
     * payroll entry posted against, so this list and 7020 Staff Advance
     * Receivable count the same events.
     */
    public function repayments(IndexSalaryAdvanceRequest $request): JsonResponse
    {
        $this->authorize('viewAny', StaffProfile::class);

        $filters = $request->validated();

        $query = Deduction::query()
            ->with([
                'payrollLine.run',
                'payrollLine.staffProfile.user',
                'payrollLine.staffProfile.branch',
            ])
            ->where('type', DeductionType::Advance)
            ->whereNotNull('reference_id')
            ->when(
                isset($filters['staff_profile_id']),
                fn (Builder $q) => $q->whereHas(
                    'payrollLine',
                    fn (Builder $line) => $line->where('staff_profile_id', $filters['staff_profile_id']),
                ),
            )
            ->latest('id');

        $repayments = $query->paginate(ApiResponse::perPage($request->query('per_page')))->withQueryString();

        $total = Money::sum(
            collect($repayments->items())->map(fn (Deduction $d): Money => $d->amountMoney()),
        );

        return ApiResponse::paginated(
            $repayments,
            SalaryAdvancePaymentResource::class,
            ['totalRepaid' => $total->toDecimalString()],
        );
    }

    /**
     * Branch and free-text search, applied through the staff profile.
     *
     * @param Builder<StaffAdvance> $query
     * @param array<string, mixed> $filters
     * @return Builder<StaffAdvance>
     */
    private function filterThroughStaff(Builder $query, array $filters): Builder
    {
        $branchId = $filters['branch_id'] ?? null;
        $search = trim((string) ($filters['search'] ?? ''));

        if ($branchId === null && $search === '') {
            return $query;
        }

        return $query->whereHas('staffProfile', function (Builder $staff) use ($branchId, $search): void {
            if ($branchId !== null) {
                $staff->where('branch_id', $branchId);
            }

            if ($search !== '') {
                $like = '%'.$search.'%';

                $staff->where(function (Builder $q) use ($like): void {
                    $q->where('employee_number', 'like', $like)
                        ->orWhereHas(
                            'user',
                            fn (Builder $user) => $user->where('name', 'like', $like)
                                ->orWhere('phone', 'like', $like),
                        );
                });
            }
        });
    }

    /**
     * The footer figures the Salary Advance tables print.
     *
     * Computed over the page rather than the whole set, unlike the charge
     * registers — these screens print a per-page "Total" row beneath the rows
     * they show, and `sumAdvances` on the frontend does the same over the same
     * rows. A whole-set figure here would disagree with the one beside it.
     *
     * @param \Illuminate\Support\Collection<int, StaffAdvance> $advances
     * @return array<string, string>
     */
    private function totalsFor($advances): array
    {
        $sum = fn (callable $each): string => Money::sum($advances->map($each))->toDecimalString();

        return [
            'totalPrincipal' => $sum(fn (StaffAdvance $a): Money => $a->amountMoney()),
            'totalInterest' => $sum(fn (StaffAdvance $a): Money => $a->interestMoney()),
            'totalChargeFee' => $sum(fn (StaffAdvance $a): Money => $a->chargeFeeMoney()),
            'totalRepayable' => $sum(fn (StaffAdvance $a): Money => $this->calculator->totalRepayable($a)),
            'totalPaid' => $sum(fn (StaffAdvance $a): Money => $a->recoveredMoney()),
            'totalRemaining' => $sum(fn (StaffAdvance $a): Money => $this->calculator->outstanding($a)),
        ];
    }
}
