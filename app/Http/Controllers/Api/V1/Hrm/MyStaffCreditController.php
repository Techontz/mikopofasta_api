<?php

namespace App\Http\Controllers\Api\V1\Hrm;

use App\Http\Requests\Api\Hrm\MyStaffLoanRequest;
use App\Http\Requests\Api\Hrm\MyStaffSalaryAdvanceRequest;
use App\Http\Resources\Api\V1\Hrm\StaffLoanResource;
use App\Http\Resources\Api\V1\Hrm\StaffSalaryAdvanceResource;
use App\Models\StaffLoanCategory;
use App\Models\StaffSalaryAdvanceCategory;
use App\Services\Hrm\StaffCredit;
use Illuminate\Http\JsonResponse;

/**
 * Spec §29/§30 — every staff member applies for a Staff Loan or a Salary Advance for THEMSELVES and follows their own requests.
 * No permission is needed; the beneficiary is always the signed-in employee.
 */
class MyStaffCreditController extends HrmController
{
    public function __construct(private readonly StaffCredit $credit) {}

    public function loans(): JsonResponse
    {
        $loans = $this->currentEmployee()->staffLoans()
            ->with(['category', 'requester', 'approver', 'financeApprover', 'disburser', 'rejecter', 'payments' => fn ($query) => $query->orderBy('id')])
            ->withSum('payments', 'amount')
            ->latest('id')
            ->get();

        return response()->json(['data' => StaffLoanResource::collection($loans)->resolve()]);
    }

    public function storeLoan(MyStaffLoanRequest $request): JsonResponse
    {
        $this->credit->submitLoan($request->loanData(), $this->currentEmployee(), $this->currentEmployee());

        return $this->message('Staff Loan Applied successfully', 201);
    }

    public function advances(): JsonResponse
    {
        $advances = $this->currentEmployee()->salaryAdvances()
            ->with(['category', 'requester', 'approver', 'financeApprover', 'disburser', 'rejecter'])
            ->latest('id')
            ->get();

        return response()->json(['data' => StaffSalaryAdvanceResource::collection($advances)->resolve()]);
    }

    public function storeAdvance(MyStaffSalaryAdvanceRequest $request): JsonResponse
    {
        $this->credit->submitAdvance($request->category(), (float) $request->input('advance_amount'), (int) $this->currentEmployee()->branch_id, $this->currentEmployee(), $this->currentEmployee());

        return $this->message('Salary Advance Requested successfully', 201);
    }

    /**
     * Loan and salary advance categories for the request forms (the HR category pages need hrm.manage).
     */
    public function categories(): JsonResponse
    {
        $companyId = $this->companyId();

        return response()->json(['data' => [
            'loan' => StaffLoanCategory::where('company_id', $companyId)->orderBy('id')->get()->map(fn (StaffLoanCategory $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'amount_from' => (float) $category->amount_from,
                'amount_to' => (float) $category->amount_to,
                'interest_rate' => (float) $category->interest_rate,
                'duration' => $category->duration,
                'repayment_from' => $category->repayment_from,
                'repayment_to' => $category->repayment_to,
                'fee' => (float) $category->fee,
            ])->values(),
            'salary_advance' => StaffSalaryAdvanceCategory::where('company_id', $companyId)->orderBy('id')->get()->map(fn (StaffSalaryAdvanceCategory $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'amount_from' => (float) $category->amount_from,
                'amount_to' => (float) $category->amount_to,
                'fee' => (float) $category->fee,
            ])->values(),
        ]]);
    }
}
