<?php

namespace App\Http\Controllers\Api\V1\Hrm;

use App\Http\Requests\Api\Hrm\NegligenceDeductionRequest;
use App\Models\ApprovalPolicy;
use App\Models\Employee;
use App\Models\NegligenceDeduction;
use App\Models\NegligenceRecovery;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\Hrm\NegligenceDeductions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * HRM → Negligence / Loss Deductions (spec §23, §57): HR creates → Finance approves → the commission payment recovers it,
 * carrying any balance forward to the next commission.
 */
class NegligenceDeductionController extends HrmController
{
    public function __construct(
        private readonly NegligenceDeductions $deductions,
        private readonly SegregationOfDuties $duties,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny('hrm.manage', 'payroll.pay');

        $query = $this->scoped(NegligenceDeduction::query())->with(['branch', 'employee', 'creator', 'approver', 'rejecter', 'recoveries']);
        $rows = $this->applyFilters($query, $request, 'created_at')
            ->when($request->filled('status'), fn ($inner) => $inner->where('status', $request->string('status')->toString()))
            ->when($request->filled('employee_id'), fn ($inner) => $inner->where('employee_id', $request->integer('employee_id')))
            ->latest('id')
            ->get();
        $canApprove = Gate::allows('payroll.pay');

        return response()->json(['data' => $rows->map(fn (NegligenceDeduction $deduction): array => [
            'id' => $deduction->id,
            'branch' => $deduction->branch?->name,
            'employee_id' => $deduction->employee_id,
            'employee' => $deduction->employee?->full_name,
            'amount' => (float) $deduction->amount,
            'recovered_amount' => (float) $deduction->recovered_amount,
            'outstanding_amount' => $deduction->outstandingAmount(),
            'reason' => $deduction->reason,
            'status' => $deduction->status,
            'created_by' => $deduction->creator?->full_name,
            'approved_by' => $deduction->approver?->full_name,
            'approved_at' => $deduction->approved_at?->toDateTimeString(),
            'rejected_by' => $deduction->rejecter?->full_name,
            'rejected_at' => $deduction->rejected_at?->toDateTimeString(),
            'rejection_reason' => $deduction->rejection_reason,
            'created_at' => $deduction->created_at?->toDateTimeString(),
            'recoveries' => $deduction->recoveries->sortBy('id')->map(fn (NegligenceRecovery $recovery): array => [
                'period' => $recovery->period->format('Y-m'),
                'commission' => (float) $recovery->commission,
                'amount' => (float) $recovery->amount,
                'outstanding_after' => (float) $recovery->outstanding_after,
                'salary_payment_id' => $recovery->salary_payment_id,
                'commission_allocation_id' => $recovery->commission_allocation_id,
            ])->values()->all(),
            ...$this->duties->flags([$deduction->created_by, $deduction->employee_id], $this->currentEmployee(), $deduction->status === NegligenceDeduction::STATUS_PENDING, $canApprove, workflow: ApprovalPolicy::PAYROLL),
        ])]);
    }

    public function store(NegligenceDeductionRequest $request): JsonResponse
    {
        $this->authorizeAny('hrm.manage');

        $employee = Employee::staff()->where('company_id', $this->companyId())->findOrFail($request->integer('employee_id'));
        $this->ensureVisible($employee);

        $deduction = $this->deductions->create($employee, (float) $request->input('amount'), $request->string('reason')->toString(), $this->currentEmployee());

        return $this->message('Negligence Deduction Saved successfully and sent to Finance for approval', 201, ['data' => ['id' => $deduction->id, 'status' => $deduction->status]]);
    }

    public function approve(NegligenceDeduction $negligenceDeduction): JsonResponse
    {
        $this->authorizeAny('payroll.pay');
        $this->ensureVisible($negligenceDeduction);

        $this->deductions->approve($negligenceDeduction, $this->currentEmployee());

        return $this->message('Negligence Deduction Approved successfully');
    }

    public function reject(Request $request, NegligenceDeduction $negligenceDeduction): JsonResponse
    {
        $this->authorizeAny('payroll.pay');
        $this->ensureVisible($negligenceDeduction);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $this->deductions->reject($negligenceDeduction, $this->currentEmployee(), $validated['reason']);

        return $this->message('Negligence Deduction Rejected successfully');
    }
}
