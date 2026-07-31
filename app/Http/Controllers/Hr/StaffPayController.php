<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Actions\ManageStaffAllowanceAction;
use App\Domain\Hr\Actions\ManageStaffDeductionAction;
use App\Domain\Hr\DTOs\StaffAllowanceData;
use App\Domain\Hr\DTOs\StaffDeductionData;
use App\Domain\Hr\Services\StaffFundReader;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\StaffAllowanceRequest;
use App\Http\Requests\Hr\StaffDeductionRequest;
use App\Http\Resources\PayslipResource;
use App\Http\Resources\StaffAllowanceResource;
use App\Http\Resources\StaffDeductionResource;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\StaffAllowance;
use App\Models\StaffDeduction;
use App\Models\StaffProfile;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What an employee draws, what is withheld, and what they were paid.
 *
 * Allowances, penalty deductions, payslips, the Staff Fund and the per-employee
 * ledger views — the parts of §10, §11, §12 and §2B that had no endpoint.
 *
 * Separate from `StaffController`, which is the employee record itself. These
 * are all statements about somebody's *pay*, and folding them in would have
 * made a controller that already handles registration, advances and performance
 * handle five more things.
 *
 * ## Who may read these
 *
 * `PayrollPolicy::viewAny` — `hr.view` **or** `payroll.finalize` — and not
 * `StaffPolicy::viewAny`, which is `hr.view` alone.
 *
 * The difference matters: §14 gives Finance `payroll.finalize` and no HR grant
 * at all, and Finance is the role that posts and pays a payroll. Gating a
 * payslip behind `hr.view` would mean the person releasing the money could not
 * see what they were releasing — and Bank → Payroll, which is a Finance screen,
 * would answer 403 to the role it exists for.
 *
 * Every **write** stays on `hr.manage`. Deciding what somebody draws is HR's,
 * whoever else may look at it.
 *
 * See docs/modules/hr-payroll.md.
 */
final class StaffPayController extends Controller
{
    // -----------------------------------------------------------------------
    // Allowances — §10
    // -----------------------------------------------------------------------

    /** GET /api/v1/staff/{staffProfile}/allowances */
    public function allowances(Request $request, StaffProfile $staffProfile): JsonResponse
    {
        $this->authorize('viewAny', PayrollRun::class);

        $allowances = $staffProfile->allowanceEntitlements()
            ->with('creator')
            ->when(
                $request->filled('period'),
                fn (Builder $q) => $q->forPeriod((string) $request->query('period')),
            )
            ->orderByRaw('period IS NOT NULL')
            ->orderBy('type')
            ->get();

        return ApiResponse::data(StaffAllowanceResource::collection($allowances));
    }

    /** POST /api/v1/staff/{staffProfile}/allowances */
    public function grantAllowance(
        StaffAllowanceRequest $request,
        StaffProfile $staffProfile,
        ManageStaffAllowanceAction $action,
    ): JsonResponse {
        $this->authorize('manage', StaffProfile::class);

        $allowance = $action->grant(
            $staffProfile,
            StaffAllowanceData::fromArray($request->validated()),
            $this->actor($request),
        );

        return ApiResponse::data(
            new StaffAllowanceResource($allowance),
            status: Response::HTTP_CREATED,
        );
    }

    /** PUT /api/v1/staff-allowances/{allowance} */
    public function updateAllowance(
        StaffAllowanceRequest $request,
        StaffAllowance $allowance,
        ManageStaffAllowanceAction $action,
    ): JsonResponse {
        $this->authorize('manage', StaffProfile::class);

        $updated = $action->update(
            $allowance,
            StaffAllowanceData::fromArray($request->validated()),
            $this->actor($request),
        );

        return ApiResponse::data(new StaffAllowanceResource($updated));
    }

    /** DELETE /api/v1/staff-allowances/{allowance} */
    public function revokeAllowance(
        Request $request,
        StaffAllowance $allowance,
        ManageStaffAllowanceAction $action,
    ): JsonResponse {
        $this->authorize('manage', StaffProfile::class);

        $action->revoke($allowance, $this->actor($request));

        return ApiResponse::data(['message' => 'Allowance stood down.']);
    }

    // -----------------------------------------------------------------------
    // Deductions — §11's penalties, the only type a person records by hand
    // -----------------------------------------------------------------------

    /** GET /api/v1/staff/{staffProfile}/deductions */
    public function deductions(Request $request, StaffProfile $staffProfile): JsonResponse
    {
        $this->authorize('viewAny', PayrollRun::class);

        $deductions = $staffProfile->payDeductions()
            ->with('creator')
            ->when(
                $request->filled('period'),
                fn (Builder $q) => $q->where('period', $request->string('period')),
            )
            ->latest('period')
            ->latest('id')
            ->get();

        return ApiResponse::data(StaffDeductionResource::collection($deductions));
    }

    /** POST /api/v1/staff/{staffProfile}/deductions */
    public function recordDeduction(
        StaffDeductionRequest $request,
        StaffProfile $staffProfile,
        ManageStaffDeductionAction $action,
    ): JsonResponse {
        $this->authorize('manage', StaffProfile::class);

        $deduction = $action->record(
            $staffProfile,
            StaffDeductionData::fromArray($request->validated()),
            $this->actor($request),
        );

        return ApiResponse::data(
            new StaffDeductionResource($deduction),
            status: Response::HTTP_CREATED,
        );
    }

    /** DELETE /api/v1/staff-deductions/{deduction} */
    public function cancelDeduction(
        Request $request,
        StaffDeduction $deduction,
        ManageStaffDeductionAction $action,
    ): JsonResponse {
        $this->authorize('manage', StaffProfile::class);

        $action->cancel($deduction, $this->actor($request));

        return ApiResponse::data(['message' => 'Deduction cancelled.']);
    }

    // -----------------------------------------------------------------------
    // Payslips — §17's "Staff Payslip", and the Bank → Payroll screen
    // -----------------------------------------------------------------------

    /**
     * GET /api/v1/payslips?period=&staff_profile_id=&branch_id=
     *
     * Payroll lines seen from the employee's side. Bank → Payroll opens on the
     * latest period, which is what an absent `period` resolves to — a screen
     * whose default view is every payslip ever issued would be unreadable and
     * would grow without bound.
     */
    public function payslips(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PayrollRun::class);

        $period = $request->filled('period')
            ? (string) $request->query('period')
            : $this->latestPeriod();

        $query = PayrollLine::query()
            ->with([
                'run',
                'staffProfile.user.role',
                'staffProfile.branch',
                'staffProfile.bankDetail',
                'allowances',
                'deductions',
            ])
            ->when($period !== null, fn (Builder $q) => $q->whereHas(
                'run',
                fn (Builder $r) => $r->where('period', $period),
            ))
            ->when(
                $request->filled('staff_profile_id'),
                fn (Builder $q) => $q->where('staff_profile_id', $request->integer('staff_profile_id')),
            )
            ->when($request->filled('branch_id'), fn (Builder $q) => $q->whereHas(
                'staffProfile',
                fn (Builder $s) => $s->where('branch_id', $request->integer('branch_id')),
            ));

        $lines = (clone $query)->get()->sortBy(
            fn (PayrollLine $l): string => $l->staffProfile->displayName(),
        )->values();

        return ApiResponse::data(
            PayslipResource::collection($lines),
            meta: [
                'period' => $period,
                // Every period that has a run, so the screen's period picker
                // offers what exists rather than guessing a range.
                'periods' => $this->availablePeriods(),
                'totalNet' => Money::sum(
                    $lines->map(fn (PayrollLine $l): Money => $l->netSalary())->all(),
                )->toDecimalString(),
                'totalGross' => Money::sum(
                    $lines->map(fn (PayrollLine $l): Money => $l->grossPay())->all(),
                )->toDecimalString(),
                'totalDeductions' => Money::sum(
                    $lines->map(fn (PayrollLine $l): Money => $l->deductionsTotal())->all(),
                )->toDecimalString(),
            ],
        );
    }

    /**
     * GET /api/v1/staff/{staffProfile}/payslips
     *
     * One employee's payment history, newest first — the panel the Bank
     * screen's detail view shows under a row.
     */
    public function staffPayslips(Request $request, StaffProfile $staffProfile): JsonResponse
    {
        $this->authorize('viewAny', PayrollRun::class);

        $lines = PayrollLine::query()
            ->with([
                'run',
                'staffProfile.user.role',
                'staffProfile.branch',
                'staffProfile.bankDetail',
                'allowances',
                'deductions',
            ])
            ->where('staff_profile_id', $staffProfile->getKey())
            ->get()
            // Sorted by the run's period rather than by id: a run generated
            // late for an earlier month belongs in that month's place.
            ->sortByDesc(fn (PayrollLine $l): string => $l->run->period)
            ->values();

        return ApiResponse::data(
            PayslipResource::collection($lines),
            meta: [
                'totalPaid' => Money::sum(
                    $lines->filter(fn (PayrollLine $l): bool => $l->run->status->isPosted())
                        ->map(fn (PayrollLine $l): Money => $l->netSalary())
                        ->all(),
                )->toDecimalString(),
            ],
        );
    }

    // -----------------------------------------------------------------------
    // Staff Fund — §12, and the per-employee ledger views of §2B
    // -----------------------------------------------------------------------

    /** GET /api/v1/staff-fund */
    public function fund(Request $request, StaffFundReader $reader): JsonResponse
    {
        $this->authorize('viewAny', PayrollRun::class);

        $position = $reader->position();

        return ApiResponse::data([
            'balance' => $position['balance']->toDecimalString(),
            'contributions' => $position['contributions']->toDecimalString(),
            'advancesOutstanding' => $position['advancesOutstanding']->toDecimalString(),
            'loansOutstanding' => $position['loansOutstanding']->toDecimalString(),
            'lentOut' => $position['lentOut']->toDecimalString(),
            'memberCount' => $position['memberCount'],
        ]);
    }

    /**
     * GET /api/v1/staff/{staffProfile}/ledger
     *
     * §2B's four accounts — Staff Control, Staff Loan, Staff Advance, Staff
     * Deductions — as the views §11 says they are, over the `staff_profile_id`
     * dimension on `journal_entry_lines`.
     */
    public function ledger(Request $request, StaffProfile $staffProfile, StaffFundReader $reader): JsonResponse
    {
        $this->authorize('viewAny', PayrollRun::class);

        $statement = $reader->statementFor($staffProfile);

        return ApiResponse::data(
            array_map(static fn (array $view): array => [
                'code' => $view['code'],
                'name' => $view['name'],
                'balance' => $view['balance']->toDecimalString(),
            ], $statement),
        );
    }

    /** The most recent period that has a payroll run. */
    private function latestPeriod(): ?string
    {
        /** @var string|null $period */
        $period = PayrollLine::query()
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_lines.payroll_run_id')
            ->max('payroll_runs.period');

        return $period;
    }

    /** @return list<string> */
    private function availablePeriods(): array
    {
        /** @var list<string> $periods */
        $periods = PayrollLine::query()
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_lines.payroll_run_id')
            ->distinct()
            ->orderByDesc('payroll_runs.period')
            ->pluck('payroll_runs.period')
            ->all();

        return $periods;
    }
}
