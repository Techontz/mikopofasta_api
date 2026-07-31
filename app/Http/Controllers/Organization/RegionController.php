<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Domain\Organization\Actions\CreateRegionAction;
use App\Domain\Organization\Actions\DeleteRegionAction;
use App\Domain\Organization\Actions\UpdateRegionAction;
use App\Domain\Organization\DTOs\RegionData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreRegionRequest;
use App\Http\Requests\Organization\UpdateRegionRequest;
use App\Http\Resources\RegionResource;
use App\Models\Region;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Regions — the geographic axis (spec §2.2, §12).
 *
 * Reads are open to any authenticated user: the customer registration wizard
 * needs the region list to build an address, and a Loan Officer holds no admin
 * permission.
 */
final class RegionController extends Controller
{
    /**
     * GET /api/v1/regions
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Region::class);

        $regions = Region::query()
            ->withCount('branches')
            ->orderBy('name')
            ->get();

        return ApiResponse::data(RegionResource::collection($regions));
    }

    /**
     * GET /api/v1/regions/{region}
     */
    public function show(Request $request, Region $region): JsonResponse
    {
        $this->authorize('view', $region);

        return ApiResponse::data(new RegionResource($region->loadCount('branches')));
    }

    /**
     * POST /api/v1/regions
     */
    public function store(StoreRegionRequest $request, CreateRegionAction $action): JsonResponse
    {
        $this->authorize('create', Region::class);

        $region = $action->handle(RegionData::fromArray($request->validated()), $this->actor($request));

        return ApiResponse::data(new RegionResource($region), status: Response::HTTP_CREATED);
    }

    /**
     * PUT /api/v1/regions/{region}
     */
    public function update(UpdateRegionRequest $request, Region $region, UpdateRegionAction $action): JsonResponse
    {
        $this->authorize('update', $region);

        $updated = $action->handle($region, RegionData::fromArray($request->validated()), $this->actor($request));

        return ApiResponse::data(new RegionResource($updated));
    }

    /**
     * DELETE /api/v1/regions/{region} — hard delete; regions carry no
     * `deleted_at` in spec §2.2.
     */
    public function destroy(Request $request, Region $region, DeleteRegionAction $action): JsonResponse
    {
        $this->authorize('delete', $region);

        $action->handle($region, $this->actor($request));

        return ApiResponse::data(['message' => 'Region deleted.']);
    }
}
