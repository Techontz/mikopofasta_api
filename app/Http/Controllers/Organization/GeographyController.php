<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Resources\DistrictResource;
use App\Http\Resources\StreetResource;
use App\Http\Resources\WardResource;
use App\Models\District;
use App\Models\Region;
use App\Models\Street;
use App\Models\Ward;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Address lookups: district → ward → street.
 *
 * These back the customer registration wizard's address step, which needs the
 * whole region → district → ward → street chain to build a structured address
 * (spec §2.4 stores all four ids on the customer). Regions themselves are
 * served by RegionController.
 *
 * Each endpoint accepts an optional parent filter so the wizard can load one
 * level at a time as the user picks, rather than shipping the entire national
 * dataset to the browser. Read-only: this is reference data with no
 * administrative screen in the frontend's route map.
 */
final class GeographyController extends Controller
{
    /**
     * GET /api/v1/districts?region_id=
     */
    public function districts(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Region::class);

        $districts = District::query()
            ->when($request->filled('region_id'), fn ($q) => $q->where('region_id', $request->integer('region_id')))
            ->orderBy('name')
            ->get();

        return ApiResponse::data(DistrictResource::collection($districts));
    }

    /**
     * GET /api/v1/wards?district_id=
     */
    public function wards(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Region::class);

        $wards = Ward::query()
            ->when($request->filled('district_id'), fn ($q) => $q->where('district_id', $request->integer('district_id')))
            ->orderBy('name')
            ->get();

        return ApiResponse::data(WardResource::collection($wards));
    }

    /**
     * GET /api/v1/streets?ward_id=
     */
    public function streets(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Region::class);

        $streets = Street::query()
            ->when($request->filled('ward_id'), fn ($q) => $q->where('ward_id', $request->integer('ward_id')))
            ->orderBy('name')
            ->get();

        return ApiResponse::data(StreetResource::collection($streets));
    }
}
