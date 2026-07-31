<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Domain\Customers\Actions\ManageCustomerCategoryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\CustomerCategoryRequest;
use App\Http\Resources\CustomerCategoryResource;
use App\Models\CustomerCategory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer categories — the KYC/risk rule engine (§2.3).
 *
 * Unpaginated: the registration wizard loads the full list to populate its
 * category picker and to render the matching dynamic form.
 */
final class CustomerCategoryController extends Controller
{
    /**
     * GET /api/v1/customer-categories
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CustomerCategory::class);

        $categories = CustomerCategory::query()
            ->withCount('customers')
            ->orderBy('name')
            ->get();

        return ApiResponse::data(CustomerCategoryResource::collection($categories));
    }

    /**
     * GET /api/v1/customer-categories/{category}
     */
    public function show(Request $request, CustomerCategory $category): JsonResponse
    {
        $this->authorize('view', $category);

        return ApiResponse::data(new CustomerCategoryResource($category->loadCount('customers')));
    }

    /**
     * POST /api/v1/customer-categories
     */
    public function store(CustomerCategoryRequest $request, ManageCustomerCategoryAction $action): JsonResponse
    {
        $this->authorize('create', CustomerCategory::class);

        $category = $action->create($request->validated(), $this->actor($request));

        return ApiResponse::data(new CustomerCategoryResource($category), status: Response::HTTP_CREATED);
    }

    /**
     * PUT /api/v1/customer-categories/{category}
     */
    public function update(CustomerCategoryRequest $request, CustomerCategory $category, ManageCustomerCategoryAction $action): JsonResponse
    {
        $this->authorize('update', $category);

        $updated = $action->update($category, $request->validated(), $this->actor($request));

        return ApiResponse::data(new CustomerCategoryResource($updated));
    }

    /**
     * DELETE /api/v1/customer-categories/{category} — soft delete.
     */
    public function destroy(Request $request, CustomerCategory $category, ManageCustomerCategoryAction $action): JsonResponse
    {
        $this->authorize('delete', $category);

        $action->delete($category, $this->actor($request));

        return ApiResponse::data(['message' => 'Customer category deleted.']);
    }
}
