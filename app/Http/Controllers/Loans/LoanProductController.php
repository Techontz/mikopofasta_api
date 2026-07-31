<?php

declare(strict_types=1);

namespace App\Http\Controllers\Loans;

use App\Domain\Loans\Actions\ManageLoanProductAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\LoanProductRequest;
use App\Http\Resources\LoanProductResource;
use App\Models\LoanProduct;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Loan products — §15's standard CRUD, fully configurable per §6.
 *
 * Unpaginated: the loan application form loads every active product to build
 * its picker and to validate amount and tenure against the chosen one.
 */
final class LoanProductController extends Controller
{
    /**
     * GET /api/v1/loan-products
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LoanProduct::class);

        $products = LoanProduct::query()
            ->with(['interestFormula', 'repaymentSchedules'])
            ->withCount('loans')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderBy('name')
            ->get();

        return ApiResponse::data(LoanProductResource::collection($products));
    }

    /**
     * GET /api/v1/loan-products/{product}
     */
    public function show(Request $request, LoanProduct $product): JsonResponse
    {
        $this->authorize('view', $product);

        return ApiResponse::data(new LoanProductResource(
            $product->load(['interestFormula', 'repaymentSchedules'])->loadCount('loans'),
        ));
    }

    /**
     * POST /api/v1/loan-products
     */
    public function store(LoanProductRequest $request, ManageLoanProductAction $action): JsonResponse
    {
        $this->authorize('create', LoanProduct::class);

        $product = $action->create($request->validated(), $this->actor($request));

        return ApiResponse::data(new LoanProductResource($product), status: Response::HTTP_CREATED);
    }

    /**
     * PUT /api/v1/loan-products/{product}
     */
    public function update(LoanProductRequest $request, LoanProduct $product, ManageLoanProductAction $action): JsonResponse
    {
        $this->authorize('update', $product);

        return ApiResponse::data(
            new LoanProductResource($action->update($product, $request->validated(), $this->actor($request))),
        );
    }

    /**
     * DELETE /api/v1/loan-products/{product} — soft delete.
     */
    public function destroy(Request $request, LoanProduct $product, ManageLoanProductAction $action): JsonResponse
    {
        $this->authorize('delete', $product);

        $action->delete($product, $this->actor($request));

        return ApiResponse::data(['message' => 'Loan product deleted.']);
    }
}
