<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Domain\Organization\Actions\CreateBranchAction;
use App\Domain\Organization\Actions\DeleteBranchAction;
use App\Domain\Organization\Actions\SetHeadOfficeAction;
use App\Domain\Organization\Actions\UpdateBranchAction;
use App\Domain\Organization\DTOs\BranchData;
use App\Domain\Organization\Services\BranchHierarchy;
use App\Domain\Organization\Services\BranchScope;
use App\Domain\Organization\Services\BranchScopeGuard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\IndexBranchRequest;
use App\Http\Requests\Organization\StoreBranchRequest;
use App\Http\Requests\Organization\UpdateBranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Branches — standard CRUD (spec §15) plus the head-office move and the
 * hierarchy view.
 */
final class BranchController extends Controller
{
    public function __construct(
        private readonly BranchScope $scope,
        private readonly BranchScopeGuard $guard,
    ) {}

    /**
     * GET /api/v1/branches
     *
     * Unpaginated by default. Branches are a lookup that the branch switcher,
     * the branch form's parent picker and the registration wizard all load
     * whole; a default page size would silently truncate those. Pass
     * `?paginate=1` for the administrative table.
     */
    public function index(IndexBranchRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Branch::class);

        $filters = $request->validated();

        $query = Branch::query()
            ->with(['region', 'zone', 'parent'])
            ->when(
                isset($filters['search']),
                fn ($q) => $q->where(function ($q) use ($filters): void {
                    $term = '%'.$filters['search'].'%';
                    $q->where('name', 'like', $term)->orWhere('phone', 'like', $term);
                }),
            )
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['type']), fn ($q) => $q->where('type', $filters['type']))
            ->when(isset($filters['region_id']), fn ($q) => $q->where('region_id', $filters['region_id']))
            ->when(isset($filters['zone_id']), fn ($q) => $q->where('zone_id', $filters['zone_id']))
            ->when(isset($filters['parent_branch_id']), fn ($q) => $q->where('parent_branch_id', $filters['parent_branch_id']))
            ->when($request->has('is_head_office'), fn ($q) => $q->where('is_head_office', $request->boolean('is_head_office')))
            ->when($request->boolean('include_deleted'), fn ($q) => $q->withTrashed())
            ->orderBy('name');

        // §13: a user only ever sees the branches in their own scope.
        $query = $this->scope->apply($query, $this->actor($request));

        if ($request->boolean('paginate')) {
            return ApiResponse::paginated(
                $query->paginate(ApiResponse::perPage($request->query('per_page')))->withQueryString(),
                BranchResource::class,
            );
        }

        return ApiResponse::data(BranchResource::collection($query->get()));
    }

    /**
     * GET /api/v1/branches/hierarchy
     *
     * The branch forest as nested nodes — §12's roll-up structure, resolved
     * server-side so the client does not have to assemble it from a flat list.
     */
    public function hierarchy(Request $request, BranchHierarchy $hierarchy): JsonResponse
    {
        $this->authorize('viewAny', Branch::class);

        $branches = $this->scope
            ->apply(Branch::query()->with(['region', 'zone']), $this->actor($request))
            ->get();

        return ApiResponse::data(
            $this->present($hierarchy->tree($branches)),
            ['total' => $branches->count()],
        );
    }

    /**
     * GET /api/v1/branches/{branch}
     */
    public function show(Request $request, Branch $branch): JsonResponse
    {
        $this->authorize('view', $branch);

        // Audits the attempt as well as refusing it (§13).
        $this->guard->authorize($this->actor($request), $branch);

        return ApiResponse::data(
            new BranchResource($branch->load(['region', 'zone', 'parent'])),
        );
    }

    /**
     * POST /api/v1/branches
     */
    public function store(StoreBranchRequest $request, CreateBranchAction $action): JsonResponse
    {
        $this->authorize('create', Branch::class);

        $branch = $action->handle(
            BranchData::fromArray($request->validated()),
            $this->actor($request),
        );

        return ApiResponse::data(new BranchResource($branch), status: Response::HTTP_CREATED);
    }

    /**
     * PUT /api/v1/branches/{branch}
     */
    public function update(UpdateBranchRequest $request, Branch $branch, UpdateBranchAction $action): JsonResponse
    {
        $this->authorize('update', $branch);

        $updated = $action->handle(
            $branch,
            BranchData::fromArray($request->validated()),
            $this->actor($request),
        );

        return ApiResponse::data(new BranchResource($updated));
    }

    /**
     * POST /api/v1/branches/{branch}/head-office
     */
    public function setHeadOffice(Request $request, Branch $branch, SetHeadOfficeAction $action): JsonResponse
    {
        $this->authorize('setHeadOffice', $branch);

        $updated = $action->handle($branch, $this->actor($request));

        return ApiResponse::data(new BranchResource($updated));
    }

    /**
     * DELETE /api/v1/branches/{branch} — soft delete.
     */
    public function destroy(Request $request, Branch $branch, DeleteBranchAction $action): JsonResponse
    {
        $this->authorize('delete', $branch);

        $action->handle($branch, $this->actor($request));

        return ApiResponse::data(['message' => 'Branch deleted.']);
    }

    /**
     * @param list<array{branch: Branch, depth: int, children: list<mixed>}> $nodes
     * @return list<array<string, mixed>>
     */
    private function present(array $nodes): array
    {
        return array_map(fn (array $node): array => [
            'branch' => new BranchResource($node['branch']),
            'depth' => $node['depth'],
            'children' => $this->present($node['children']),
        ], $nodes);
    }
}
