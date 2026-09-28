<?php

namespace App\Http\Controllers\Api\V1\Hrm;

use App\Models\ApprovalPolicy;
use App\Models\Employee;
use App\Models\StaffFundWithdrawal;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\Hrm\StaffFund;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * HRM → Staff Fund: balance, statement, member balances and staff benefit claims (spec §27: HR prepares → Finance reviews →
 * Finance approves → Finance pays from the single STAFF FUND A/C).
 */
class StaffFundController extends HrmController
{
    public function __construct(private readonly StaffFund $fund) {}

    /**
     * Spec §25: the Fund Account (cash balance, statement, movements) is Finance's (payroll.pay / reports.financial); HR sees
     * only the member contribution and benefit records.
     */
    public function show(Request $request): JsonResponse
    {
        $this->authorizeAny('hrm.manage', 'payroll.pay', 'reports.financial');
        abort_unless($this->seesAllBranches(), 403, 'You do not have permission to perform this action.');

        $from = $request->filled('from') ? CarbonImmutable::parse($request->string('from')->toString()) : null;
        $to = $request->filled('to') ? CarbonImmutable::parse($request->string('to')->toString()) : null;
        $report = $this->fund->report($this->companyId(), $from, $to);

        if (! Gate::any(['payroll.pay', 'reports.financial'])) {
            $report = Arr::only($report, ['contributions', 'total_benefit_record', 'members']);
        }

        return response()->json(['data' => $report]);
    }

    /**
     * Spec §27 staff benefit claims with the recorded benefit entitlement and the audit trail of the claim workflow. HR (hrm.manage)
     * and Finance (payroll.pay) see every claim; the claimant's own claims are listed by `mine`.
     */
    public function claims(Request $request): JsonResponse
    {
        $this->authorizeAny('hrm.manage', 'payroll.pay');
        abort_unless($this->seesAllBranches(), 403, 'You do not have permission to perform this action.');
        $request->validate(['status' => ['nullable', Rule::in(array_keys(StaffFundWithdrawal::STATUS_LABELS))]]);

        $claims = StaffFundWithdrawal::where('company_id', $this->companyId())
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('employee_id'), fn ($query) => $query->where('employee_id', $request->integer('employee_id')))
            ->with(['employee.branch', 'preparer', 'reviewer', 'approver', 'rejecter', 'payer', 'journalEntry'])
            ->latest('id')
            ->get();

        return response()->json(['data' => $claims->map(fn (StaffFundWithdrawal $claim): array => $this->presentClaim($claim, Gate::allows('payroll.pay')))->values()->all()]);
    }

    /**
     * Recorded benefit entitlement of one employee before HR prepares a claim.
     */
    public function entitlement(Employee $employee): JsonResponse
    {
        $this->authorizeAny('hrm.manage', 'payroll.pay');
        abort_unless((int) $employee->company_id === $this->companyId(), 404);

        return response()->json(['data' => ['employee_id' => $employee->id, 'employee' => $employee->full_name, ...$this->fund->entitlement($this->companyId(), $employee->id)]]);
    }

    /**
     * HR prepares a benefit claim (spec §27 step 1).
     */
    public function withdraw(Request $request): JsonResponse
    {
        $this->authorizeAny('hrm.manage');

        $validated = $request->validate([
            'empl_id' => ['required', Rule::exists('employees', 'id')->where('company_id', $this->companyId())],
            'amount' => ['required', 'numeric', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $claim = $this->fund->prepareClaim(Employee::staff()->findOrFail($validated['empl_id']), (float) $validated['amount'], $validated['reason'], $this->currentEmployee());

        return $this->message('Staff Benefit Claim prepared and sent to Finance for review', 201, ['data' => ['id' => $claim->id, 'status' => $claim->status, 'entitlement' => (float) $claim->entitlement]]);
    }

    public function reviewClaim(StaffFundWithdrawal $claim): JsonResponse
    {
        $this->authorizeClaimDecision($claim);
        $this->fund->reviewClaim($claim, $this->currentEmployee());

        return $this->message('Staff Benefit Claim taken into Finance review');
    }

    public function approveClaim(StaffFundWithdrawal $claim): JsonResponse
    {
        $this->authorizeClaimDecision($claim);
        $this->fund->approveClaim($claim, $this->currentEmployee());

        return $this->message('Staff Benefit Claim Approved successfully');
    }

    public function rejectClaim(Request $request, StaffFundWithdrawal $claim): JsonResponse
    {
        $this->authorizeClaimDecision($claim);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->fund->rejectClaim($claim, $this->currentEmployee(), $validated['reason']);

        return $this->message('Staff Benefit Claim Rejected successfully');
    }

    public function payClaim(StaffFundWithdrawal $claim): JsonResponse
    {
        $this->authorizeClaimDecision($claim);
        $this->fund->payClaim($claim, $this->currentEmployee());

        return $this->message('Staff Benefit Claim Paid successfully');
    }

    private function authorizeClaimDecision(StaffFundWithdrawal $claim): void
    {
        $this->authorizeAny('payroll.pay');
        abort_unless((int) $claim->company_id === $this->companyId(), 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentClaim(StaffFundWithdrawal $claim, bool $canDecide): array
    {
        $duties = app(SegregationOfDuties::class);
        $initiators = [$claim->prepared_by, $claim->employee_id];
        $flags = fn (array $statuses): array => $duties->flags($initiators, $this->currentEmployee(), in_array($claim->status, $statuses, true), $canDecide, workflow: ApprovalPolicy::PAYROLL);

        return [
            'id' => $claim->id,
            'employee_id' => $claim->employee_id,
            'employee' => $claim->employee?->full_name,
            'branch' => $claim->employee?->branch?->name,
            'amount' => (float) $claim->amount,
            'entitlement' => $claim->entitlement !== null ? (float) $claim->entitlement : null,
            'reason' => $claim->reason,
            'status' => $claim->status,
            'status_label' => $claim->statusLabel(),
            'prepared_by' => $claim->preparer?->full_name,
            'prepared_at' => $claim->prepared_at?->toDateTimeString(),
            'reviewed_by' => $claim->reviewer?->full_name,
            'reviewed_at' => $claim->reviewed_at?->toDateTimeString(),
            'approved_by' => $claim->approver?->full_name,
            'approved_at' => $claim->approved_at?->toDateTimeString(),
            'rejected_by' => $claim->rejecter?->full_name,
            'rejected_at' => $claim->rejected_at?->toDateTimeString(),
            'rejection_reason' => $claim->rejection_reason,
            'paid_by' => $claim->payer?->full_name,
            'paid_at' => $claim->paid_at?->toDateTimeString(),
            'journal_reference' => $claim->journalEntry?->reference,
            'created_at' => $claim->created_at?->toDateTimeString(),
            ...$flags([StaffFundWithdrawal::STATUS_PREPARED, StaffFundWithdrawal::STATUS_FINANCE_REVIEW]),
            ...collect($flags([StaffFundWithdrawal::STATUS_APPROVED]))->mapWithKeys(fn ($value, string $key): array => [str_replace('approve', 'pay', $key) => $value])->all(),
        ];
    }
}
