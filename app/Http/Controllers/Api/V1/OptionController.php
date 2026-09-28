<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Region;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lightweight {value,label} lists for dropdowns (replaces the live fetch_* endpoints).
 */
class OptionController extends ApiController
{
    public function branchOptions(Request $request): JsonResponse
    {
        // branches_only: real branches, without Head Office (HQ is not a branch — e.g. branch expenses, petty cash).
        $branches = $this->visibleBranches()
            ->when($request->boolean('branches_only'), fn ($branches) => $branches->reject(fn ($branch): bool => (bool) $branch->is_head_office))
            ->map(fn ($branch): array => ['value' => (string) $branch->id, 'label' => $branch->name]);

        if ($request->boolean('with_all')) {
            $branches->push(['value' => 'all', 'label' => 'ALL']);
        }

        return response()->json(['data' => $branches->values()]);
    }

    public function regions(): JsonResponse
    {
        return response()->json(['data' => Region::orderBy('id')->get()->map(fn (Region $region): array => ['value' => (string) $region->id, 'label' => $region->name])]);
    }

    public function employees(Request $request): JsonResponse
    {
        $employees = $this->scoped(Employee::query())->staff()
            ->where('status', 'active')
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->orderBy('first_name')
            ->get();

        return response()->json(['data' => $employees->map(fn (Employee $employee): array => ['value' => (string) $employee->id, 'label' => $employee->full_name])]);
    }

    public function customers(Request $request): JsonResponse
    {
        $customers = $this->scoped(Customer::query())
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->latest('id')
            ->limit(2000)
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'customer_code']);

        return response()->json(['data' => $customers->map(fn (Customer $customer): array => [
            'value' => (string) $customer->id,
            'label' => $customer->full_name.($request->boolean('with_code') ? " / {$customer->customer_code}" : ''),
        ])]);
    }
}
