<?php

declare(strict_types=1);

namespace App\Http\Controllers\Loans;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\EligibilityRuleRequest;
use App\Http\Resources\CustomerCategoryResource;
use App\Http\Resources\InterestFormulaResource;
use App\Http\Resources\RepaymentScheduleResource;
use App\Models\CategoryProductEligibility;
use App\Models\CustomerCategory;
use App\Models\InterestFormula;
use App\Models\LoanProduct;
use App\Models\RepaymentSchedule;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The remaining §2.3 configuration: interest formulas, repayment schedules and
 * the category → product eligibility pivot.
 *
 * Formulas and schedules are read here. What may be *changed* about them, and
 * by whom, lives in Admin\SystemConfigurationController — Settings → Interest
 * Formulas and Settings → Repayment Schedules. Reading stays here because the
 * loan application form is the main caller and these are its lookups.
 *
 * A formula's `code` is what LoanScheduleGenerator switches on, so a fourth
 * formula would be a row no calculation knows how to honour: only its name and
 * description are editable, and there is no create or delete. A schedule's
 * `frequency_days` is a number the generator divides by rather than a branch,
 * so schedules are open — see ManageRepaymentScheduleAction.
 */
final class LoanConfigurationController extends Controller
{
    /**
     * GET /api/v1/interest-formulas
     */
    public function interestFormulas(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LoanProduct::class);

        return ApiResponse::data(
            InterestFormulaResource::collection(
                // Counted for the settings screen, which shows what each
                // formula is carrying. The application form ignores it.
                InterestFormula::query()->withCount('products')->orderBy('id')->get(),
            ),
        );
    }

    /**
     * GET /api/v1/repayment-schedules
     */
    public function repaymentSchedules(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LoanProduct::class);

        return ApiResponse::data(
            RepaymentScheduleResource::collection(
                // `loans` and `products` are what the settings screen's guards
                // are about: a schedule with either cannot change frequency or
                // be retired, and the screen needs to say why.
                RepaymentSchedule::query()
                    ->withCount(['loans', 'products'])
                    ->orderBy('frequency_days')
                    ->get(),
            ),
        );
    }

    /**
     * GET /api/v1/customer-categories/{category}/eligibility
     *
     * Which products this category may apply for, and on what terms.
     */
    public function eligibility(Request $request, CustomerCategory $category): JsonResponse
    {
        $this->authorize('view', $category);

        $rules = CategoryProductEligibility::query()
            ->with('product')
            ->where('customer_category_id', $category->getKey())
            ->get();

        return ApiResponse::data([
            'category' => new CustomerCategoryResource($category),
            'rules' => $rules->map(fn (CategoryProductEligibility $rule): array => [
                'id' => (string) $rule->getKey(),
                'loanProductId' => (string) $rule->loan_product_id,
                'loanProductName' => $rule->product?->name,
                'maxAmountOverride' => $rule->max_amount_override,
                'requiresExtraApproval' => $rule->requires_extra_approval,
            ])->all(),
        ]);
    }

    /**
     * PUT /api/v1/customer-categories/{category}/eligibility
     *
     * Replaces the whole rule set for this category. Idempotent, and immune to
     * two administrators editing different rows from a stale view — the same
     * reasoning as the permission matrix in Phase 2.
     */
    public function updateEligibility(
        EligibilityRuleRequest $request,
        CustomerCategory $category,
        AuditLogger $audit,
    ): JsonResponse {
        $this->authorize('update', $category);

        $actor = $this->actor($request);

        /** @var list<array<string, mixed>> $rules */
        $rules = $request->validated('rules');

        DB::transaction(function () use ($category, $rules, $actor, $audit): void {
            $before = CategoryProductEligibility::query()
                ->where('customer_category_id', $category->getKey())
                ->pluck('loan_product_id')
                ->all();

            CategoryProductEligibility::query()
                ->where('customer_category_id', $category->getKey())
                ->delete();

            foreach ($rules as $rule) {
                CategoryProductEligibility::query()->create([
                    'customer_category_id' => $category->getKey(),
                    'loan_product_id' => $rule['loanProductId'],
                    'max_amount_override' => $rule['maxAmountOverride'] ?? null,
                    'requires_extra_approval' => $rule['requiresExtraApproval'] ?? false,
                ]);
            }

            $audit->log(
                AuditAction::LoanProductEligibilityUpdated,
                $category,
                before: ['loan_product_ids' => $before],
                after: ['loan_product_ids' => array_column($rules, 'loanProductId')],
                actor: $actor,
            );
        });

        return $this->eligibility($request, $category->refresh());
    }
}
