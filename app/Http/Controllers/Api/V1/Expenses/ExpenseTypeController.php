<?php

namespace App\Http\Controllers\Api\V1\Expenses;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Expenses\ExpenseTypeRequest;
use App\Http\Resources\Api\V1\Expenses\ExpenseTypeResource;
use App\Models\ExpenseType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Expense categories for the three live registers: Register Branch Expenses (branch),
 * Headquarters Expenses → Register Expenses (hq) and Bank → Register Bank Expenses (bank).
 * Each category gets its own EXPENSES ledger sub-account on first posting (Documents: "Kila category = Ledger yake").
 */
class ExpenseTypeController extends ApiController
{
    /**
     * Permission keys that manage each register. The HQ register is a company setting: only Super Admin and Admin
     * (settings.manage) register HQ expense categories — HQ/Finance request against them but never add or change them.
     *
     * @var array<string, list<string>>
     */
    public const MANAGE_PERMISSIONS = [
        'branch' => ['expenses.request', 'settings.manage'],
        'hq' => ['settings.manage'],
        'bank' => ['bank.manage'],
    ];

    /**
     * Permission keys that may read each register (requesters and approvers need the dropdown).
     *
     * @var array<string, list<string>>
     */
    public const VIEW_PERMISSIONS = [
        'branch' => ['expenses.request', 'settings.manage', 'expenses.approve_branch', 'expenses.approve_hq'],
        'hq' => ['hq.manage', 'expenses.approve_hq', 'settings.manage'],
        'bank' => ['bank.manage'],
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $scope = $this->scopeFrom($request);
        $this->authorizeAny(...self::VIEW_PERMISSIONS[$scope]);

        return ExpenseTypeResource::collection($this->types($scope));
    }

    public function options(Request $request): JsonResponse
    {
        $scope = $this->scopeFrom($request);
        $this->authorizeAny(...self::VIEW_PERMISSIONS[$scope]);

        return response()->json(['data' => $this->types($scope)->map(fn (ExpenseType $type): array => ['value' => (string) $type->id, 'label' => $type->name])]);
    }

    public function store(ExpenseTypeRequest $request): JsonResponse
    {
        $scope = $request->string('scope')->toString();
        $this->authorizeAny(...self::MANAGE_PERMISSIONS[$scope]);

        $type = ExpenseType::create([
            'company_id' => $this->currentEmployee()->company_id,
            'scope' => $scope,
            'name' => $request->expenseName(),
        ]);

        return $this->message('Expenses Registered successfully', 201, ['data' => new ExpenseTypeResource($type)]);
    }

    public function update(ExpenseTypeRequest $request, ExpenseType $expenseType): JsonResponse
    {
        $this->authorizeAny(...self::MANAGE_PERMISSIONS[$expenseType->scope]);

        $expenseType->update(['name' => $request->expenseName()]);

        return $this->message('Expenses Updated successfully', 200, ['data' => new ExpenseTypeResource($expenseType)]);
    }

    public function destroy(ExpenseType $expenseType): JsonResponse
    {
        $this->authorizeAny(...self::MANAGE_PERMISSIONS[$expenseType->scope]);

        if ($expenseType->requests()->exists()) {
            return $this->message('Expenses has requests and cannot be deleted', 422);
        }

        $expenseType->delete();

        return $this->message('Expenses Deleted successfully');
    }

    private function scopeFrom(Request $request): string
    {
        $validated = $request->validate(['scope' => ['required', Rule::in(array_keys(self::MANAGE_PERMISSIONS))]]);

        return $validated['scope'];
    }

    /**
     * @return Collection<int, ExpenseType>
     */
    private function types(string $scope): Collection
    {
        return ExpenseType::where('company_id', $this->currentEmployee()->company_id)->where('scope', $scope)->orderBy('id')->get();
    }
}
