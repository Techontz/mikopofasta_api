<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Loans\Services\BranchApprovalRouter;
use App\Domain\Organization\Actions\ConfigureBranchRouteAction;
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
use App\Http\Requests\Organization\UpdateBranchApprovalRouteRequest;
use App\Http\Requests\Organization\UpdateBranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use App\Models\BranchApprovalRoute;
use App\Models\LoanApprovalStage;
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

        /*
         * The Branch List's customer-status counts.
         *
         * Five numbers per branch, computed in the query rather than by loading
         * every customer: the list is a handful of rows and each count is one
         * correlated subquery.
         *
         * The four buckets are MUTUALLY EXCLUSIVE, in order of what an officer
         * needs to see first — a customer in default is counted there and
         * nowhere else, even if they also hold a healthy loan. Without the
         * exclusion the columns would sum to more than the branch has
         * customers, which is exactly the sort of number nobody can act on.
         *
         * A customer with no loan at all appears only in `all`. That is
         * deliberate: they are on the book, but none of the four states
         * describes them.
         */
        $default = LoanStatus::defaultedStates();
        $open = LoanStatus::openStates();
        $pending = LoanStatus::inFlightStates();
        $done = LoanStatus::settledStates();

        $inState = static fn (array $states) => static fn ($loans) => $loans->whereIn('status', $states);

        $query = Branch::query()
            ->with(['region', 'zone', 'parent'])
            ->withCount([
                'customers as customers_all_count',

                'customers as customers_default_count' => static fn ($q) => $q
                    ->whereHas('loans', $inState($default)),

                'customers as customers_active_count' => static fn ($q) => $q
                    ->whereHas('loans', $inState($open))
                    ->whereDoesntHave('loans', $inState($default)),

                'customers as customers_pending_count' => static fn ($q) => $q
                    ->whereHas('loans', $inState($pending))
                    ->whereDoesntHave('loans', $inState([...$default, ...$open])),

                'customers as customers_done_count' => static fn ($q) => $q
                    ->whereHas('loans', $inState($done))
                    ->whereDoesntHave('loans', $inState([...$default, ...$open, ...$pending])),
            ])
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
     * GET /api/v1/branches/{branch}/approval-route
     *
     * The chain an application raised at this branch would walk today, stage by
     * stage, with the reason each one is in or out. The reason is served rather
     * than inferred in the browser: "included because the branch has a zone" and
     * "included because an administrator said so" look identical from a boolean,
     * and they are the difference between routing that follows the branch and
     * routing somebody pinned.
     */
    public function approvalRoute(Request $request, Branch $branch, BranchApprovalRouter $router): JsonResponse
    {
        $this->authorize('view', $branch);

        return ApiResponse::data($this->routePayload($branch, $router));
    }

    /**
     * PUT /api/v1/branches/{branch}/approval-route — D4's configurability.
     *
     * Overrides are replaced wholesale rather than merged: the screen sends the
     * complete picture it is showing, and a partial update would leave a stage
     * pinned by a rule nobody can see on the form they just submitted.
     */
    public function updateApprovalRoute(
        UpdateBranchApprovalRouteRequest $request,
        Branch $branch,
        ConfigureBranchRouteAction $action,
        BranchApprovalRouter $router,
    ): JsonResponse {
        $this->authorize('update', $branch);

        $action->handle($branch, $request->overrides(), $this->actor($request));

        return ApiResponse::data($this->routePayload($branch->fresh(), $router));
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
     * @return array<string, mixed>
     */
    private function routePayload(Branch $branch, BranchApprovalRouter $router): array
    {
        $route = $router->routeFor($branch)->keyBy(fn (LoanApprovalStage $s): int => (int) $s->getKey());

        $overrides = BranchApprovalRoute::query()
            ->where('branch_id', $branch->getKey())
            ->pluck('is_required', 'loan_approval_stage_id');

        return [
            'branchId' => (string) $branch->getKey(),
            'branchName' => $branch->name,
            'zoneId' => $branch->zone_id === null ? null : (string) $branch->zone_id,
            'stages' => LoanApprovalStage::chain()->map(function (LoanApprovalStage $stage) use ($route, $overrides): array {
                $id = (int) $stage->getKey();
                $override = $overrides->has($id) ? (bool) $overrides->get($id) : null;

                return [
                    'stageId' => (string) $id,
                    'code' => $stage->code,
                    'name' => $stage->name,
                    'sequence' => $stage->sequence,
                    'requiresBranchZone' => (bool) $stage->requires_branch_zone,
                    'included' => $route->has($id),
                    // Null means "following the default"; true/false means an
                    // administrator pinned it either way.
                    'override' => $override,
                ];
            })->values()->all(),
        ];
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
