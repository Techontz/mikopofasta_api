<?php

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\District;
use App\Models\Region;
use App\Models\Ward;
use App\Services\Customers\GeographyImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Tanzania geography (Region → District → Ward) from the database, and its CSV import (Settings → Geography).
 */
class GeographyController extends ApiController
{
    public function regions(): JsonResponse
    {
        return response()->json(['data' => Region::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Region $region): array => ['id' => $region->id, 'name' => $region->name])]);
    }

    public function districts(Request $request): JsonResponse
    {
        if (! $request->filled('region_id')) {
            return response()->json(['data' => []]);
        }

        return response()->json(['data' => District::query()->where('region_id', $request->integer('region_id'))->orderBy('name')->get(['id', 'name', 'region_id'])
            ->map(fn (District $district): array => ['id' => $district->id, 'name' => $district->name, 'regionId' => $district->region_id])]);
    }

    public function wards(Request $request): JsonResponse
    {
        if (! $request->filled('district_id')) {
            return response()->json(['data' => []]);
        }

        return response()->json(['data' => Ward::query()->where('district_id', $request->integer('district_id'))->orderBy('name')->get(['id', 'name', 'district_id'])
            ->map(fn (Ward $ward): array => ['id' => $ward->id, 'name' => $ward->name, 'districtId' => $ward->district_id])]);
    }

    /**
     * Counts on file, plus per-region district and ward counts.
     */
    public function status(GeographyImporter $importer): JsonResponse
    {
        $this->authorizeAny('settings.manage');

        $regions = Region::query()->withCount('districts')->orderBy('name')->get();
        $wardCounts = Ward::query()->join('districts', 'districts.id', '=', 'wards.district_id')
            ->selectRaw('districts.region_id, COUNT(*) as total')->groupBy('districts.region_id')->pluck('total', 'region_id');

        return response()->json(['data' => $importer->counts() + [
            'perRegion' => $regions->map(fn (Region $region): array => [
                'id' => $region->id,
                'name' => $region->name,
                'districts' => (int) $region->districts_count,
                'wards' => (int) ($wardCounts[$region->id] ?? 0),
            ])->values(),
        ]]);
    }

    public function import(Request $request, GeographyImporter $importer): JsonResponse
    {
        $this->authorizeAny('settings.manage');

        $request->validate(
            ['file' => ['required', 'file', 'mimes:csv,txt', 'max:10240']],
            ['file.required' => 'Choose the CSV file to import.', 'file.mimes' => 'The geography file must be a CSV.', 'file.max' => 'The geography file must not be larger than 10 MB.'],
        );

        try {
            $summary = $importer->import($request->file('file')->getRealPath());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        return $this->message(sprintf('Imported %d of %d rows. %d rejected.', $summary['imported'], $summary['rows'], count($summary['rejected'])), 200, [
            'data' => $summary + ['counts' => $importer->counts()],
        ]);
    }
}
