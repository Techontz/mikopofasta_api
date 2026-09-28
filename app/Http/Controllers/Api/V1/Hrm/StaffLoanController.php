<?php

namespace App\Http\Controllers\Api\V1\Hrm;

use App\Enums\StaffCreditStatus;
use App\Http\Requests\Api\Hrm\StaffLoanRequest;
use App\Http\Resources\Api\V1\Hrm\StaffLoanResource;
use App\Models\Employee;
use App\Models\StaffLoan;
use App\Services\Hrm\StaffCredit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HRM → Staff Loan (live admin/staff_loan, staff_loan_active). Spec §29/§49: Submitted → HR Approved (Admin Approved for an HR
 * user's own request, §32) → Finance Approved → Disbursed from the Fund Account → Repaying (payroll) → Completed.
 * Staff apply for themselves through {@see MyStaffCreditController}; HR may still enter a request on an employee's behalf.
 */
class StaffLoanController extends HrmController
{
    private const WITH = ['branch', 'employee', 'category', 'requester', 'approver', 'financeApprover', 'disburser', 'rejecter'];

    public function __construct(private readonly StaffCredit $credit) {}

    /**
     * Submitted (awaiting HR / Admin review), approved (awaiting Finance approval or disbursement) and the "Approved List"
     * (disbursed / repaying / completed).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeViewer();

        $base = fn () => $this->applyFilters($this->scoped(StaffLoan::query())->with(self::WITH)->withSum('payments', 'amount'), $request, 'staff_loans.created_at');

        return response()->json(['data' => [
            'pending' => StaffLoanResource::collection($base()->where('status', StaffCreditStatus::Submitted->value)->orderBy('id')->get())->resolve(),
            'approved' => StaffLoanResource::collection($base()->whereIn('status', [...StaffCreditStatus::reviewed(), StaffCreditStatus::FinanceApproved->value])->orderBy('id')->get())->resolve(),
            'disbursed' => StaffLoanResource::collection($base()->whereIn('status', [...StaffCreditStatus::recovering(), StaffCreditStatus::Completed->value])->latest('id')->get())->resolve(),
            'rejected' => StaffLoanResource::collection($base()->where('status', StaffCreditStatus::Rejected->value)->latest('id')->get())->resolve(),
        ]]);
    }

    public function active(): JsonResponse
    {
        $this->authorizeAny('hrm.manage', 'payroll.pay');

        $loans = $this->scoped(StaffLoan::query())
            ->whereIn('status', StaffCreditStatus::recovering())
            ->with([...self::WITH, 'payments' => fn ($query) => $query->orderBy('id')])
            ->withSum('payments', 'amount')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => StaffLoanResource::collection($loans)->resolve()]);
    }

    /**
     * HR enters a request on behalf of an employee.
     */
    public function store(StaffLoanRequest $request): JsonResponse
    {
        $this->authorizeAny('hrm.manage');
        $this->assertBranchAccessible($request->integer('blanch_id'));

        $this->credit->submitLoan($request->loanData(), Employee::findOrFail($request->integer('empl_id')), $this->currentEmployee());

        return $this->message('Staff Loan Applied successfully', 201);
    }

    /**
     * HR review, or Admin review when the loan benefits an HR user.
     */
    public function approve(StaffLoan $loan): JsonResponse
    {
        $this->authorizeViewer();
        $this->ensureVisible($loan);

        $this->credit->review($loan, $this->currentEmployee());

        return $this->message('Staff Loan Approved successfully');
    }

    public function financeApprove(StaffLoan $loan): JsonResponse
    {
        $this->authorizeAny('payroll.pay');
        $this->ensureVisible($loan);

        $this->credit->financeApprove($loan, $this->currentEmployee());

        return $this->message('Staff Loan Finance Approved successfully');
    }

    public function reject(Request $request, StaffLoan $loan): JsonResponse
    {
        $this->authorizeViewer();
        $this->ensureVisible($loan);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $this->credit->reject($loan, $this->currentEmployee(), $validated['reason'] ?? null);

        return $this->message('Staff Loan Rejected successfully');
    }

    public function disburse(StaffLoan $loan): JsonResponse
    {
        $this->authorizeAny('payroll.pay');
        $this->ensureVisible($loan);

        $this->credit->disburseLoan($loan, $this->currentEmployee());

        return $this->message('Staff Loan Disbursed successfully');
    }

    /**
     * "Pay loan" modal (Deposit): cash repayment into the Staff Fund.
     */
    public function pay(Request $request, StaffLoan $loan): JsonResponse
    {
        $this->authorizeAny('payroll.pay', 'hrm.manage');
        $this->ensureVisible($loan);

        $validated = $request->validate(['amount' => ['required', 'numeric', 'min:1']]);
        $this->credit->repayLoan($loan->loadSum('payments', 'amount'), (float) $validated['amount'], $this->currentEmployee());

        return $this->message('Loan Paid successfully');
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
