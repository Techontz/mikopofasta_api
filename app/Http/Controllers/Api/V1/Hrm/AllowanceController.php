<?php

namespace App\Http\Controllers\Api\V1\Hrm;

use App\Http\Requests\Api\Hrm\StaffAllowanceRequest;
use App\Models\ApprovalPolicy;
use App\Models\PayrollRun;
use App\Models\StaffAllowance;
use App\Services\Approvals\SegregationOfDuties;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * HRM → Staff Allowance (live admin/staff_allowance) with the spec §24 / §58 chain:
 * HR creates (pending) → Finance approves (Approved / Awaiting Payroll) → the payroll of the period pays it (paid).
 * Finance approval moves no money; payroll approval recognises Dr Allowance Expense / Cr Staff Payable. Legacy recurring
 * allowances (`active`) keep flowing into every payroll until HR stops them.
 */
class AllowanceController extends HrmController
{
    public function __construct(private readonly SegregationOfDuties $duties) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny('hrm.manage', 'payroll.pay');

        $query = $this->scoped(StaffAllowance::query())->with(['branch', 'employee', 'creator', 'approver', 'payrollRun']);
        $allowances = $this->applyFilters($query, $request, 'created_at')
            ->when($request->filled('status'), fn ($inner) => $inner->where('status', $request->string('status')->toString()))
            ->latest('id')
            ->get();
        $canApprove = Gate::allows('payroll.pay');

        return response()->json(['data' => $allowances->map(fn (StaffAllowance $allowance): array => [
            'id' => $allowance->id,
            'branch' => $allowance->branch?->name,
            'employee_id' => $allowance->employee_id,
            'employee' => $allowance->employee?->full_name,
            'amount' => (float) $allowance->amount,
            'reason' => $allowance->reason,
            'description' => $allowance->description,
            'payroll_period' => $allowance->payroll_period?->format('Y-m'),
            'recurring' => (bool) $allowance->recurring,
            'status' => $allowance->status,
            'status_label' => $allowance->statusLabel(),
            'created_by' => $allowance->creator?->full_name,
            'approved_by' => $allowance->approver?->full_name,
            'approved_at' => $allowance->approved_at?->toDateTimeString(),
            'rejection_reason' => $allowance->rejection_reason,
            'payroll' => $allowance->payrollRun?->period->format('F Y'),
            'paid_at' => $allowance->paid_at?->toDateTimeString(),
            'created_at' => $allowance->created_at?->toDateString(),
            ...$this->duties->flags([$allowance->created_by, $allowance->employee_id], $this->currentEmployee(), $allowance->status === StaffAllowance::STATUS_PENDING, $canApprove, workflow: ApprovalPolicy::PAYROLL),
        ])]);
    }

    public function store(StaffAllowanceRequest $request): JsonResponse
    {
        $this->authorizeAny('hrm.manage');
        $this->assertBranchAccessible($request->integer('blanch_id'));

        $period = $request->filled('payroll_period')
            ? CarbonImmutable::createFromFormat('Y-m-d', $request->string('payroll_period')->toString().'-01')
            : CarbonImmutable::now();

        StaffAllowance::create([
            'company_id' => $this->companyId(),
            'branch_id' => $request->integer('blanch_id'),
            'employee_id' => $request->integer('empl_id'),
            'amount' => (float) $request->input('new_amount'),
            'reason' => $request->input('reason') ?: 'other',
            'payroll_period' => $period->startOfMonth()->toDateString(),
            'recurring' => false,
            'description' => $request->input('remaks_allow'),
            'status' => StaffAllowance::STATUS_PENDING,
            'created_by' => $this->currentEmployee()->id,
        ]);

        return $this->message('Allowance Saved successfully and sent to Finance for approval', 201);
    }

    /**
     * Finance approval: the allowance waits for its payroll (no money moves). Rule 6: the HR employee who created it and the
     * employee who receives it cannot approve it.
     */
    public function approve(StaffAllowance $allowance): JsonResponse
    {
        $this->authorizeAny('payroll.pay');
        $this->ensureVisible($allowance);
        $this->assertPending($allowance);
        $this->duties->assertCanApprove([$allowance->created_by, $allowance->employee_id], $this->currentEmployee(), 'allowance', workflow: ApprovalPolicy::PAYROLL);

        $allowance->update(['status' => StaffAllowance::STATUS_APPROVED, 'approved_by' => $this->currentEmployee()->id, 'approved_at' => now()]);

        return $this->message('Allowance Approved successfully');
    }

    public function reject(Request $request, StaffAllowance $allowance): JsonResponse
    {
        $this->authorizeAny('payroll.pay');
        $this->ensureVisible($allowance);
        $this->assertPending($allowance);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->duties->assertCanApprove([$allowance->created_by, $allowance->employee_id], $this->currentEmployee(), 'allowance', workflow: ApprovalPolicy::PAYROLL);

        $allowance->update([
            'status' => StaffAllowance::STATUS_REJECTED,
            'rejected_by' => $this->currentEmployee()->id,
            'rejected_at' => now(),
            'rejection_reason' => $validated['reason'],
        ]);

        return $this->message('Allowance Rejected successfully');
    }

    /**
     * Stops a recurring allowance, or withdraws one that no approved or paid payroll has taken yet.
     */
    public function stop(StaffAllowance $allowance): JsonResponse
    {
        $this->authorizeAny('hrm.manage');
        $this->ensureVisible($allowance);

        $stoppable = in_array($allowance->status, [StaffAllowance::STATUS_ACTIVE, StaffAllowance::STATUS_PENDING, StaffAllowance::STATUS_APPROVED], true)
            && ($allowance->payroll_run_id === null || $allowance->payrollRun?->status === PayrollRun::STATUS_DRAFT);
        if (! $stoppable) {
            throw ValidationException::withMessages(['status' => 'This allowance is already in an approved payroll and can not be stopped']);
        }

        $allowance->update(['status' => StaffAllowance::STATUS_STOPPED, 'payroll_run_id' => null]);

        return $this->message('Allowance Stopped successfully');
    }

    /**
     * @throws ValidationException
     */
    private function assertPending(StaffAllowance $allowance): void
    {
        if ($allowance->status !== StaffAllowance::STATUS_PENDING) {
            throw ValidationException::withMessages(['status' => 'Allowance is not pending']);
        }
    }
}
