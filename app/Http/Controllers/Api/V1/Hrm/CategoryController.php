<?php

namespace App\Http\Controllers\Api\V1\Hrm;

use App\Http\Requests\Api\Hrm\StaffLoanCategoryRequest;
use App\Http\Requests\Api\Hrm\StaffSalaryAdvanceCategoryRequest;
use App\Models\StaffLoanCategory;
use App\Models\StaffSalaryAdvance;
use App\Models\StaffSalaryAdvanceCategory;
use Illuminate\Http\JsonResponse;

/**
 * HRM → Staff Loan category (live admin/empl_loan_category) and Staff salary advance category
 * (live admin/staff_salary_category).
 */
class CategoryController extends HrmController
{
    public function loanCategories(): JsonResponse
    {
        $this->authorizeAny('hrm.manage', 'payroll.pay');

        return response()->json(['data' => StaffLoanCategory::where('company_id', $this->companyId())->orderBy('id')->get()->map(fn (StaffLoanCategory $category): array => [
            'id' => $category->id,
            'name' => $category->name,
            'amount_from' => (float) $category->amount_from,
            'amount_to' => (float) $category->amount_to,
            'interest_rate' => (float) $category->interest_rate,
            'duration' => $category->duration,
            'repayment_from' => $category->repayment_from,
            'repayment_to' => $category->repayment_to,
            'fee' => (float) $category->fee,
        ])]);
    }

    public function storeLoanCategory(StaffLoanCategoryRequest $request): JsonResponse
    {
        $this->authorizeAny('hrm.manage');

        StaffLoanCategory::create($request->categoryData() + ['company_id' => $this->companyId()]);

        return $this->message('Loan Category Saved successfully', 201);
    }

    public function updateLoanCategory(StaffLoanCategoryRequest $request, StaffLoanCategory $category): JsonResponse
    {
        $this->authorizeAny('hrm.manage');
        abort_unless($category->company_id === $this->companyId(), 404);

        $category->update($request->categoryData());

        return $this->message('Loan Category Updated successfully');
    }

    public function destroyLoanCategory(StaffLoanCategory $category): JsonResponse
    {
        $this->authorizeAny('hrm.manage');
        abort_unless($category->company_id === $this->companyId(), 404);

        if ($category->staffLoans()->exists()) {
            return $this->message('Loan Category has staff loans and cannot be deleted', 422);
        }

        $category->delete();

        return $this->message('Loan Category Deleted successfully');
    }

    public function advanceCategories(): JsonResponse
    {
        $this->authorizeAny('hrm.manage', 'payroll.pay');

        return response()->json(['data' => StaffSalaryAdvanceCategory::where('company_id', $this->companyId())->orderBy('id')->get()->map(fn (StaffSalaryAdvanceCategory $category): array => [
            'id' => $category->id,
            'name' => $category->name,
            'amount_from' => (float) $category->amount_from,
            'amount_to' => (float) $category->amount_to,
            'fee' => (float) $category->fee,
        ])]);
    }

    public function storeAdvanceCategory(StaffSalaryAdvanceCategoryRequest $request): JsonResponse
    {
        $this->authorizeAny('hrm.manage');

        StaffSalaryAdvanceCategory::create($request->categoryData() + ['company_id' => $this->companyId()]);

        return $this->message('Category Saved successfully', 201);
    }

    public function updateAdvanceCategory(StaffSalaryAdvanceCategoryRequest $request, StaffSalaryAdvanceCategory $category): JsonResponse
    {
        $this->authorizeAny('hrm.manage');
        abort_unless($category->company_id === $this->companyId(), 404);

        $category->update($request->categoryData());

        return $this->message('Category Updated successfully');
    }

    public function destroyAdvanceCategory(StaffSalaryAdvanceCategory $category): JsonResponse
    {
        $this->authorizeAny('hrm.manage');
        abort_unless($category->company_id === $this->companyId(), 404);

        if (StaffSalaryAdvance::where('staff_salary_advance_category_id', $category->id)->exists()) {
            return $this->message('Category has salary advances and cannot be deleted', 422);
        }

        $category->delete();

        return $this->message('Category Deleted successfully');
    }
}
