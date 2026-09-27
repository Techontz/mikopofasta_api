<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Settings\LoanFeeCategoryRequest;
use App\Http\Resources\Api\V1\Settings\LoanCategoryResource;
use App\Models\LoanCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Settings → Loan Fee (live admin/loan_fee): fee mode (by loan product / general) and per-product fee and insurance.
 */
class LoanFeeController extends ApiController
{
    /**
     * Live option values of the "Loan Fee Category" dropdown mapped to companies.loan_fee_mode.
     *
     * @var array<string, string>
     */
    private const MODES = ['LOAN PRODUCT' => 'product', 'GENERAL' => 'general'];

    public function index(): JsonResponse
    {
        $this->authorizeAny('settings.manage');

        $company = $this->currentCompany();

        return response()->json(['data' => [
            'mode' => $company->loan_fee_mode === 'general' ? 'GENERAL' : 'LOAN PRODUCT',
            'categories' => LoanCategoryResource::collection(LoanCategory::where('company_id', $company->id)->orderBy('id')->get())->resolve(),
        ]]);
    }

    public function updateMode(Request $request): JsonResponse
    {
        $this->authorizeAny('settings.manage');

        $validated = $request->validate(['fee_category' => ['required', Rule::in(array_keys(self::MODES))]]);

        $this->currentCompany()->update(['loan_fee_mode' => self::MODES[$validated['fee_category']]]);

        return $this->message('Loan Fee Category Updated successfully');
    }

    public function updateCategory(LoanFeeCategoryRequest $request, LoanCategory $loanCategory): JsonResponse
    {
        $this->authorizeAny('settings.manage');

        $loanCategory->update($request->categoryData());

        return $this->message('Loan Fee Updated successfully');
    }
}
