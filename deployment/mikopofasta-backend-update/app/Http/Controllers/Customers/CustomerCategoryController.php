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
 * Customer Types — the broad customer classification, and the KYC/risk rule
 * engine behind it (§2.3).
 *
 * The table is `customer_categories` and the payload property is
 * `customerCategoryId`; both keep their names because production customers,
 * eligibility rules, required documents and the KYC engine all point at them.
 * The business term, and everything a person reads, is Customer Type.
 *
 * Unpaginated: the registration wizard loads the whole list to populate its
 * picker and to render the matching dynamic form.
 *
 * WRITES ARE SUPER ADMIN ONLY — CustomerCategoryPolicy. Reads stay open,
 * because registration needs them and a Loan Officer holds no admin
 * permission.
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
            /* `?activeOnly=1` is what the registration wizard asks for: a type
               the institution has retired must not be offered to a new
               customer, while the administration screen still lists it so it
               can be switched back on. */
            ->when($request->boolean('activeOnly'), fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')
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
