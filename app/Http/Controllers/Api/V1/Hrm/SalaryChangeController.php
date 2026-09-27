<?php

namespace App\Http\Controllers\Api\V1\Hrm;

use App\Http\Resources\Api\V1\Hrm\SalaryChangeRequestResource;
use App\Models\SalaryChangeRequest;
use App\Services\Hrm\SalaryChanges;
use App\Services\Hrm\StaffCredit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HRM → Salary changes (spec §32): changes of existing salaries proposed by HR, waiting for Finance (or Admin) approval.
 */
class SalaryChangeController extends HrmController
{
    public function __construct(private readonly SalaryChanges $changes) {}

    /**
     * Submitted changes first, then the latest decided ones.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeViewer();

        $base = fn () => SalaryChangeRequest::where('company_id', $this->companyId())
            ->whereHas('employee', fn ($query) => $this->scoped($query))
            ->with(['employee', 'requester', 'approver', 'rejecter']);

        return response()->json(['data' => [
            'pending' => SalaryChangeRequestResource::collection($base()->where('status', SalaryChangeRequest::STATUS_SUBMITTED)->orderBy('id')->get())->resolve(),
            'decided' => SalaryChangeRequestResource::collection($base()->where('status', '!=', SalaryChangeRequest::STATUS_SUBMITTED)->latest('id')->limit(100)->get())->resolve(),
        ]]);
    }

    public function approve(SalaryChangeRequest $salaryChange): JsonResponse
    {
        $this->authorizeViewer();
        $this->ensureVisible($salaryChange->employee);

        $this->changes->approve($salaryChange, $this->currentEmployee());

        return $this->message('Salary change approved successfully');
    }

    public function reject(Request $request, SalaryChangeRequest $salaryChange): JsonResponse
    {
        $this->authorizeViewer();
        $this->ensureVisible($salaryChange->employee);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $this->changes->reject($salaryChange, $this->currentEmployee(), $validated['reason'] ?? null);

        return $this->message('Salary change rejected successfully');
    }

    /**
     * HR (sees the proposals), Finance (approves) and Admin (approves HR users' own salary changes).
     */
    private function authorizeViewer(): void
    {
        if (! app(StaffCredit::class)->isAdmin($this->currentEmployee())) {
            $this->authorizeAny('hrm.manage', 'payroll.approve', 'payroll.pay');
        }
    }
}
