<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\AccessControl;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Base class for API v1 controllers: authorization, data scoping and common filters.
 */
abstract class ApiController extends Controller
{
    protected function authorizeAny(string ...$permissions): void
    {
        abort_unless(collect($permissions)->contains(fn (string $permission): bool => Gate::allows($permission)), 403, 'You do not have permission to perform this action.');
    }

    /**
     * Restrict a query to the signed-in employee's company and branch scope.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function scoped(Builder $query, string $branchColumn = 'branch_id'): Builder
    {
        return app(AccessControl::class)->scope($query, $this->currentEmployee(), $branchColumn);
    }

    /**
     * Branches visible to the signed-in employee.
     *
     * @return Collection<int, Branch>
     */
    protected function visibleBranches(): Collection
    {
        $ids = app(AccessControl::class)->branchIds($this->currentEmployee());

        return Branch::where('company_id', $this->currentEmployee()->company_id)
            ->when($ids !== null, fn (Builder $query) => $query->whereIn('id', $ids))
            ->orderBy('id')
            ->get();
    }

    /**
     * Apply the live system's standard filter modal (branch incl. "all", from/to dates).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function applyFilters(Builder $query, Request $request, ?string $dateColumn = null, string $branchColumn = 'branch_id'): Builder
    {
        $branch = $request->input('branch_id');
        if ($branch !== null && $branch !== '' && $branch !== 'all') {
            $query->where($query->getModel()->qualifyColumn($branchColumn), (int) $branch);
        }

        if ($dateColumn !== null && $request->filled('from')) {
            $query->whereDate($dateColumn, '>=', CarbonImmutable::parse($request->string('from')->toString())->toDateString());
        }
        if ($dateColumn !== null && $request->filled('to')) {
            $query->whereDate($dateColumn, '<=', CarbonImmutable::parse($request->string('to')->toString())->toDateString());
        }

        return $query;
    }

    /**
     * Ensure a branch id supplied by the client is within the employee's scope.
     */
    protected function assertBranchAccessible(int $branchId): void
    {
        $ids = app(AccessControl::class)->branchIds($this->currentEmployee());
        $belongs = Branch::whereKey($branchId)->where('company_id', $this->currentEmployee()->company_id)->exists();

        abort_unless($belongs && ($ids === null || in_array($branchId, $ids, true)), 403, 'You do not have access to this branch.');
    }

    protected function message(string $message, int $status = 200, array $extra = []): JsonResponse
    {
        return response()->json(['message' => $message] + $extra, $status);
    }
}
