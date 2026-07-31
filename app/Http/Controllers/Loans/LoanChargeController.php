<?php

declare(strict_types=1);

namespace App\Http\Controllers\Loans;

use App\Domain\Loans\Actions\CreatePenaltySettingAction;
use App\Domain\Loans\Actions\DeleteLoanFeeAction;
use App\Domain\Loans\Actions\DeletePenaltySettingAction;
use App\Domain\Loans\Actions\UpdateReserveSettingAction;
use App\Domain\Loans\Actions\UpsertLoanFeeAction;
use App\Domain\Loans\DTOs\LoanFeeData;
use App\Domain\Loans\DTOs\PenaltySettingData;
use App\Domain\Loans\Policies\LoanChargePolicy;
use App\Enums\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\StorePenaltySettingRequest;
use App\Http\Requests\Loans\UpdateLoanFeeRequest;
use App\Http\Requests\Loans\UpdateReserveSettingRequest;
use App\Http\Resources\LoanFeeResource;
use App\Http\Resources\PenaltySettingResource;
use App\Http\Resources\ReserveSettingResource;
use App\Models\LoanFee;
use App\Models\LoanProduct;
use App\Models\PenaltySetting;
use App\Models\ReserveSetting;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Loan Charges & Reserve — Settings → Loan Fee / Penalty / Reserve Setting.
 *
 * See docs/modules/loan-charges.md. One controller for the three because they
 * are one screen group behind one permission; separate controllers would only
 * distribute the same authorize() call three ways.
 *
 * Unpaginated throughout: there is one reserve percentage, a handful of penalty
 * defaults, and one fee row per loan product.
 */
final class LoanChargeController extends Controller
{
    // -----------------------------------------------------------------
    // Loan Fee
    // -----------------------------------------------------------------

    /**
     * GET /api/v1/loan-fees
     *
     * Every loan product, with its fee where one is configured. The legacy
     * screen lists all categories whether or not a fee is set, so products
     * without one are returned with a null fee rather than omitted.
     */
    public function loanFees(Request $request): JsonResponse
    {
        $this->authorizeCharge('viewAny', $request);

        $products = LoanProduct::query()
            ->with('fee')
            ->orderBy('name')
            ->get();

        $rows = $products->map(fn (LoanProduct $product): array => [
            'loanProductId' => (string) $product->id,
            'productName' => $product->name,
            'productCode' => $product->code,
            'minAmount' => $product->min_amount,
            'maxAmount' => $product->max_amount,
            'interestRate' => $product->interest_rate,
            'fee' => $product->fee === null
                ? null
                : (new LoanFeeResource($product->fee))->resolve($request),
        ]);

        return ApiResponse::data($rows);
    }

    /**
     * PUT /api/v1/loan-fees/{product}
     */
    public function upsertLoanFee(
        UpdateLoanFeeRequest $request,
        LoanProduct $product,
        UpsertLoanFeeAction $action,
    ): JsonResponse {
        $this->authorizeCharge('manage', $request);

        $fee = $action->handle($product, LoanFeeData::fromArray($request->validated()), $this->actor($request));

        return ApiResponse::data(new LoanFeeResource($fee));
    }

    /**
     * DELETE /api/v1/loan-fees/{product}
     */
    public function deleteLoanFee(Request $request, LoanProduct $product, DeleteLoanFeeAction $action): JsonResponse
    {
        $this->authorizeCharge('manage', $request);

        $fee = LoanFee::query()->where('loan_product_id', $product->id)->first();

        if ($fee === null) {
            return ApiResponse::error(
                'This loan category has no fee configured.',
                ErrorCode::ResourceNotFound,
                status: Response::HTTP_NOT_FOUND,
            );
        }

        $action->handle($fee, $this->actor($request));

        return ApiResponse::data(['message' => 'Loan fee cleared.']);
    }

    // -----------------------------------------------------------------
    // Penalty
    // -----------------------------------------------------------------

    /**
     * GET /api/v1/penalty-settings
     */
    public function penaltySettings(Request $request): JsonResponse
    {
        $this->authorizeCharge('viewAny', $request);

        $settings = PenaltySetting::query()->latest('id')->get();

        return ApiResponse::data(PenaltySettingResource::collection($settings));
    }

    /**
     * POST /api/v1/penalty-settings
     */
    public function storePenaltySetting(
        StorePenaltySettingRequest $request,
        CreatePenaltySettingAction $action,
    ): JsonResponse {
        $this->authorizeCharge('manage', $request);

        $setting = $action->handle(PenaltySettingData::fromArray($request->validated()), $this->actor($request));

        return ApiResponse::data(new PenaltySettingResource($setting), status: Response::HTTP_CREATED);
    }

    /**
     * DELETE /api/v1/penalty-settings/{penaltySetting}
     */
    public function deletePenaltySetting(
        Request $request,
        PenaltySetting $penaltySetting,
        DeletePenaltySettingAction $action,
    ): JsonResponse {
        $this->authorizeCharge('manage', $request);

        $action->handle($penaltySetting, $this->actor($request));

        return ApiResponse::data(['message' => 'Penalty setting deleted.']);
    }

    // -----------------------------------------------------------------
    // Reserve
    // -----------------------------------------------------------------

    /**
     * GET /api/v1/reserve-setting
     */
    public function reserveSetting(Request $request): JsonResponse
    {
        $this->authorizeCharge('viewAny', $request);

        return ApiResponse::data(new ReserveSettingResource(ReserveSetting::singleton()));
    }

    /**
     * PUT /api/v1/reserve-setting
     */
    public function updateReserveSetting(
        UpdateReserveSettingRequest $request,
        UpdateReserveSettingAction $action,
    ): JsonResponse {
        $this->authorizeCharge('manage', $request);

        $setting = $action->handle((string) $request->validated('percentage'), $this->actor($request));

        return ApiResponse::data(new ReserveSettingResource($setting));
    }

    /**
     * The policy covers all three settings and is not bound to a model, so it
     * is called directly rather than through $this->authorize().
     */
    private function authorizeCharge(string $ability, Request $request): void
    {
        $actor = $this->actor($request);
        $policy = app(LoanChargePolicy::class);

        abort_unless($policy->{$ability}($actor), Response::HTTP_FORBIDDEN);
    }
}
