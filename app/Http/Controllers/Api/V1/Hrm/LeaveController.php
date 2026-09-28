<?php

namespace App\Http\Controllers\Api\V1\Hrm;

use App\Http\Requests\Api\Hrm\LeaveRequest;
use App\Models\Employee;
use App\Models\Leave;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HRM → Staff Leave (live admin/leave).
 */
class LeaveController extends HrmController
{
    public function index(): JsonResponse
    {
        $this->authorizeAny('hrm.manage');

        $leaves = Leave::where('company_id', $this->companyId())
            ->whereHas('employee', fn ($query) => $this->scoped($query))
            ->with('employee.branch')
            ->latest('id')
            ->get();

        return response()->json(['data' => $leaves->map(fn (Leave $leave): array => [
            'id' => $leave->id,
            'employee_id' => $leave->employee_id,
            'employee' => $leave->employee->full_name,
            'phone' => $leave->employee->phone,
            'branch' => $leave->employee->branch?->name,
            'position' => $leave->employee->position,
            'start_date' => $leave->start_date->toDateString(),
            'end_date' => $leave->end_date->toDateString(),
            'remarks' => $leave->remarks,
            'status' => $leave->status,
        ])]);
    }

    public function store(LeaveRequest $request): JsonResponse
    {
        $this->authorizeAny('hrm.manage');

        $employee = Employee::staff()->findOrFail($request->integer('empl_id'));
        $this->ensureVisible($employee);

        Leave::create([
            'company_id' => $this->companyId(),
            'employee_id' => $employee->id,
            'start_date' => $request->date('stat_date'),
            'end_date' => $request->date('end_date'),
            'remarks' => $request->string('remaks')->trim()->toString(),
            'status' => 'pending',
        ]);

        return $this->message('Leave Saved successfully', 201);
    }

    /**
     * Inferred: the live Action column is empty; HR approves or rejects a pending leave.
     */
    public function decide(Request $request, Leave $leave): JsonResponse
    {
        $this->authorizeAny('hrm.manage');
        abort_unless($leave->company_id === $this->companyId(), 404);
        $this->ensureVisible($leave->employee);

        $validated = $request->validate(['status' => ['required', 'in:approved,rejected']]);

        if ($leave->status !== 'pending') {
            return $this->message('Leave is not pending', 422);
        }

        $leave->update(['status' => $validated['status'], 'approved_by' => $this->currentEmployee()->id]);

        return $this->message($validated['status'] === 'approved' ? 'Leave Approved successfully' : 'Leave Rejected successfully');
    }
}
