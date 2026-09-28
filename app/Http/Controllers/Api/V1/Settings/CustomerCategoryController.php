<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Settings\CustomerCategoryRequest;
use App\Http\Resources\Api\V1\Settings\CustomerCategoryResource;
use App\Models\CustomerCategory;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Customer types (customer_categories). Any signed-in user may read them; only the Super Administrator may
 * create, update or delete them (CUSTOMER_MODULE_SPEC.md §9).
 */
class CustomerCategoryController extends ApiController
{
    /**
     * GET /customer-categories — every type of the company (Settings); `activeOnly=1` limits it to the selectable ones.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return $this->listing($request->boolean('activeOnly'));
    }

    /**
     * GET /customer-types — read-only alias listing only the selectable (active) customer types in display order:
     * the options for registration, customer filters, loans and reports.
     */
    public function types(): AnonymousResourceCollection
    {
        return $this->listing(activeOnly: true);
    }

    private function listing(bool $activeOnly): AnonymousResourceCollection
    {
        $companyId = $this->currentEmployee()->company_id;
        $query = $activeOnly
            ? CustomerCategory::query()->selectable($companyId)
            : CustomerCategory::query()->where('company_id', $companyId)->orderBy('sort_order')->orderBy('name');

        return CustomerCategoryResource::collection($query->withCount('customers')->get());
    }

    public function show(CustomerCategory $customerCategory): CustomerCategoryResource
    {
        return new CustomerCategoryResource($customerCategory->loadCount('customers'));
    }

    public function store(CustomerCategoryRequest $request): JsonResponse
    {
        $category = DB::transaction(fn (): CustomerCategory => CustomerCategory::query()->create($request->categoryData() + [
            'company_id' => $this->currentEmployee()->company_id,
            'key' => Str::lower($request->string('code')->toString()),
            'form_schema' => [],
            'created_by' => $this->currentEmployee()->id,
        ]));

        return $this->message('Customer type created.', 201, ['data' => new CustomerCategoryResource($category->loadCount('customers'))]);
    }

    public function update(CustomerCategoryRequest $request, CustomerCategory $customerCategory): JsonResponse
    {
        DB::transaction(fn () => $customerCategory->update($request->categoryData()));

        return $this->message('Customer type updated.', 200, ['data' => new CustomerCategoryResource($customerCategory->loadCount('customers'))]);
    }

    /**
     * A customer type that has loan categories cannot be deleted (422). Otherwise the type is soft-deleted.
     */
    public function destroy(CustomerCategory $customerCategory): JsonResponse
    {
        $employee = $this->currentEmployee();
        abort_unless($employee instanceof Employee && $employee->role?->key === 'super_admin', 403, 'Only the Super Administrator can create, edit or delete customer types.');

        if ($customerCategory->loanCategories()->exists()) {
            return $this->message('This customer type has loan categories and cannot be deleted. Move or delete its loan categories first.', 422);
        }

        $customerCategory->delete();

        return $this->message('Customer type deleted.');
    }
}
