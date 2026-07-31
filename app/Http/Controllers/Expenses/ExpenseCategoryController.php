<?php

declare(strict_types=1);

namespace App\Http\Controllers\Expenses;

use App\Domain\Expenses\Actions\CreateExpenseCategoryAction;
use App\Domain\Expenses\Actions\DeleteExpenseCategoryAction;
use App\Domain\Expenses\Actions\UpdateExpenseCategoryAction;
use App\Domain\Expenses\DTOs\ExpenseCategoryData;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Expenses\Concerns\AuthorizesExpenses;
use App\Http\Requests\Expenses\StoreExpenseCategoryRequest;
use App\Http\Requests\Expenses\UpdateExpenseCategoryRequest;
use App\Http\Resources\ExpenseCategoryResource;
use App\Models\ExpenseCategory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The expense register — Expenses → Register Branch Expenses, Headquarters
 * Expenses → Register Expenses, and Settings → Expense Categories.
 *
 * Three screens, one collection: the first two are this list filtered by
 * `scope`, and the third is all of it. Serving them from one endpoint is what
 * stops the Settings screen and the operational one disagreeing about which
 * names exist.
 *
 * Unpaginated, like the other register screens — a company keeps a page of
 * expense names, not a book of them, and the table filters in the browser.
 */
final class ExpenseCategoryController extends Controller
{
    use AuthorizesExpenses;

    /** GET /api/v1/expense-categories?scope=&with_balances= */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeExpenses('view', $request);

        /*
         * `chartAccount` is always loaded — the Settings screen reads its code,
         * and the response shape should not depend on which screen asked. The
         * balances behind it are loaded only on request, because summing them
         * is a second query per row and the register screens never show it.
         */
        $with = $request->boolean('with_balances')
            ? ['chartAccount.balances']
            : ['chartAccount'];

        $categories = ExpenseCategory::query()
            ->with($with)
            ->when($request->filled('scope'), fn ($q) => $q->where('scope', $request->string('scope')))
            ->orderBy('scope')
            ->orderBy('name')
            ->get();

        return ApiResponse::data(ExpenseCategoryResource::collection($categories));
    }

    /** POST /api/v1/expense-categories */
    public function store(StoreExpenseCategoryRequest $request, CreateExpenseCategoryAction $action): JsonResponse
    {
        $this->authorizeExpenses('manageCategories', $request);

        $category = $action->handle(
            ExpenseCategoryData::fromArray($request->validated()),
            $this->actor($request),
        );

        return ApiResponse::data(new ExpenseCategoryResource($category), status: Response::HTTP_CREATED);
    }

    /** PUT /api/v1/expense-categories/{category} */
    public function update(
        UpdateExpenseCategoryRequest $request,
        ExpenseCategory $category,
        UpdateExpenseCategoryAction $action,
    ): JsonResponse {
        $this->authorizeExpenses('manageCategories', $request);

        // The scope is not editable, so it is taken from the record rather than
        // the payload — the DTO needs one, and the stored value is the answer.
        $data = ExpenseCategoryData::fromArray([
            'name' => $request->validated('name'),
            'scope' => $category->scope->value,
        ]);

        return ApiResponse::data(new ExpenseCategoryResource($action->handle($category, $data, $this->actor($request))));
    }

    /** DELETE /api/v1/expense-categories/{category} */
    public function destroy(Request $request, ExpenseCategory $category, DeleteExpenseCategoryAction $action): JsonResponse
    {
        $this->authorizeExpenses('manageCategories', $request);

        $action->handle($category, $this->actor($request));

        return ApiResponse::data(['message' => "{$category->name} removed from the register."]);
    }
}
