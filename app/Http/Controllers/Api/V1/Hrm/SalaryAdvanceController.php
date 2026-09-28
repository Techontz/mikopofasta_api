<?php

namespace App\Http\Controllers\Api\V1\Hrm;

use App\Enums\Account;
use App\Enums\StaffCreditStatus;
use App\Http\Requests\Api\Hrm\StaffSalaryAdvanceRequest;
use App\Http\Resources\Api\V1\Hrm\StaffSalaryAdvanceResource;
use App\Models\Employee;
use App\Models\StaffSalaryAdvance;
use App\Services\Hrm\StaffCredit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * HRM → Salary Advance (live admin/sallry_advance). Spec §30/§49: Submitted → HR Approved (Admin Approved for an HR user's own
 * request, §32) → Finance Approved → Disbursed ONLY from the Fund Account → Repaying (next salaries) → Completed.
 */
class SalaryAdvanceController extends HrmController
{
    private const WITH = ['branch', 'employee', 'category', 'requester', 'approver', 'financeApprover', 'disburser', 'rejecter'];

    public function __construct(private readonly StaffCredit $credit) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeViewer();

        $base = fn () => $this->applyFilters($this->scoped(StaffSalaryAdvance::query())->with(self::WITH), $request, 'staff_salary_advances.created_at');

        return response()->json(['data' => [
            'pending' => StaffSalaryAdvanceResource::collection($base()->where('status', StaffCreditStatus::Submitted->value)->orderBy('id')->get())->resolve(),
            'approved' => StaffSalaryAdvanceResource::collection($base()->whereIn('status', [...StaffCreditStatus::reviewed(), StaffCreditStatus::FinanceApproved->value])->orderBy('id')->get())->resolve(),
            'disbursed' => StaffSalaryAdvanceResource::collection($base()->whereIn('status', [...StaffCreditStatus::recovering(), StaffCreditStatus::Completed->value])->latest('id')->get())->resolve(),
            'rejected' => StaffSalaryAdvanceResource::collection($base()->where('status', StaffCreditStatus::Rejected->value)->latest('id')->get())->resolve(),
        ]]);
    }

    /**
     * HR enters a request on behalf of an employee.
     */
    public function store(StaffSalaryAdvanceRequest $request): JsonResponse
    {
        $this->authorizeAny('hrm.manage');
        $this->assertBranchAccessible($request->integer('blanch_id'));

        $this->credit->submitAdvance($request->category(), (float) $request->input('advance_amount'), $request->integer('blanch_id'), Employee::findOrFail($request->integer('empl_id')), $this->currentEmployee());

        return $this->message('Salary Advance Requested successfully', 201);
    }

    /**
     * HR review, or Admin review when the advance benefits an HR user.
     */
    public function approve(StaffSalaryAdvance $advance): JsonResponse
    {
        $this->authorizeViewer();
        $this->ensureVisible($advance);

        $this->credit->review($advance, $this->currentEmployee());

        return $this->message('Salary Advance Approved successfully');
    }

    public function financeApprove(StaffSalaryAdvance $advance): JsonResponse
    {
        $this->authorizeAny('payroll.pay');
        $this->ensureVisible($advance);

        $this->credit->financeApprove($advance, $this->currentEmployee());

        return $this->message('Salary Advance Finance Approved successfully');
    }

    public function reject(Request $request, StaffSalaryAdvance $advance): JsonResponse
    {
        $this->authorizeViewer();
        $this->ensureVisible($advance);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $this->credit->reject($advance, $this->currentEmployee(), $validated['reason'] ?? null);

        return $this->message('Salary Advance Rejected successfully');
    }

    /**
     * §30: paid only from the Fund Account (`ac_id` is optional and, when sent, must be the Staff Fund A/C).
     */
    public function disburse(Request $request, StaffSalaryAdvance $advance): JsonResponse
    {
        $this->authorizeAny('payroll.pay');
        $this->ensureVisible($advance);

        $request->validate(
            ['ac_id' => ['nullable', Rule::in([Account::StaffFundCash->value])]],
            ['ac_id.in' => 'Staff salary advances are paid only from the STAFF FUND A/C.'],
        );
        $this->credit->disburseAdvance($advance, $this->currentEmployee());

        return $this->message('Salary Advance Disbursed successfully');
    }

    /**
     * HR, Finance, and Admin (who approves §32 self-requests of HR users).
     */
    private function authorizeViewer(): void
    {
        if (! $this->credit->isAdmin($this->currentEmployee())) {
            $this->authorizeAny('hrm.manage', 'payroll.approve', 'payroll.pay');
        }
    }
}
