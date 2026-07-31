<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Domain\Organization\Actions\CreateZoneAction;
use App\Domain\Organization\Actions\DeleteZoneAction;
use App\Domain\Organization\Actions\UpdateZoneAction;
use App\Domain\Organization\DTOs\ZoneData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreZoneRequest;
use App\Http\Requests\Organization\UpdateZoneRequest;
use App\Http\Resources\ZoneResource;
use App\Models\Zone;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zones — the commission/oversight grouping over branches (spec §12).
 *
 * Unpaginated: there are a handful of zones and the branch form, the zone tab
 * and the commission screens all want the full list.
 */
final class ZoneController extends Controller
{
    /**
     * GET /api/v1/zones
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Zone::class);

        $zones = Zone::query()
            ->with('manager')
            ->withCount('branches')
            ->when($request->boolean('include_deleted'), fn ($q) => $q->withTrashed())
            ->orderBy('name')
            ->get();

        return ApiResponse::data(ZoneResource::collection($zones));
    }

    /**
     * GET /api/v1/zones/{zone}
     */
    public function show(Request $request, Zone $zone): JsonResponse
    {
        $this->authorize('view', $zone);

        return ApiResponse::data(
            new ZoneResource($zone->load('manager')->loadCount('branches')),
        );
    }

    /**
     * POST /api/v1/zones
     */
    public function store(StoreZoneRequest $request, CreateZoneAction $action): JsonResponse
    {
        $this->authorize('create', Zone::class);

        $zone = $action->handle(ZoneData::fromArray($request->validated()), $this->actor($request));

        return ApiResponse::data(new ZoneResource($zone), status: Response::HTTP_CREATED);
    }

    /**
     * PUT /api/v1/zones/{zone}
     */
    public function update(UpdateZoneRequest $request, Zone $zone, UpdateZoneAction $action): JsonResponse
    {
        $this->authorize('update', $zone);

        $updated = $action->handle($zone, ZoneData::fromArray($request->validated()), $this->actor($request));

        return ApiResponse::data(new ZoneResource($updated));
    }

    /**
     * DELETE /api/v1/zones/{zone} — soft delete.
     */
    public function destroy(Request $request, Zone $zone, DeleteZoneAction $action): JsonResponse
    {
        $this->authorize('delete', $zone);

        $action->handle($zone, $this->actor($request));

        return ApiResponse::data(['message' => 'Zone deleted.']);
    }
}
