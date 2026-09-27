<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Settings\ZoneRequest;
use App\Http\Resources\Api\V1\Settings\ZoneResource;
use App\Models\Branch;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Settings → Zones. Documents: a zone groups branches overseen by a Zone Manager, who sees reports of
 * those branches only and does not apply loans (the zone_manager role has no loans.apply).
 */
class ZoneController extends ApiController
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorizeAny('settings.manage');

        return ZoneResource::collection($this->zones()->get());
    }

    public function store(ZoneRequest $request): JsonResponse
    {
        $this->authorizeAny('settings.manage');

        $zone = DB::transaction(function () use ($request): Zone {
            $zone = Zone::create(['company_id' => $this->currentEmployee()->company_id, 'name' => $request->string('zone_name')->trim()->toString()]);
            if ($request->has('branch_ids')) {
                $this->syncBranches($zone, $request->input('branch_ids', []));
            }

            return $zone;
        });

        return $this->message('Zone Registered successfully', 201, ['data' => new ZoneResource($this->zones()->find($zone->id))]);
    }

    public function show(Zone $zone): ZoneResource
    {
        $this->authorizeAny('settings.manage');

        return new ZoneResource($this->zones()->find($zone->id));
    }

    public function update(ZoneRequest $request, Zone $zone): JsonResponse
    {
        $this->authorizeAny('settings.manage');

        DB::transaction(function () use ($request, $zone): void {
            $zone->update(['name' => $request->string('zone_name')->trim()->toString()]);
            if ($request->has('branch_ids')) {
                $this->syncBranches($zone, $request->input('branch_ids', []));
            }
        });

        return $this->message('Zone Updated successfully', 200, ['data' => new ZoneResource($this->zones()->find($zone->id))]);
    }

    public function destroy(Zone $zone): JsonResponse
    {
        $this->authorizeAny('settings.manage');

        if ($zone->employees()->whereHas('role', fn ($role) => $role->whereIn('key', $this->zoneRoleKeys()))->exists()) {
            return $this->message('Zone has zone managers and cannot be deleted', 422);
        }

        DB::transaction(function () use ($zone): void {
            Branch::where('zone_id', $zone->id)->update(['zone_id' => null]);
            $zone->delete();
        });

        return $this->message('Zone Deleted successfully');
    }

    /**
     * Role keys whose data scope is a zone (Zone Manager).
     *
     * @return list<string>
     */
    private function zoneRoleKeys(): array
    {
        return collect(config('permissions.roles'))->filter(fn (array $role): bool => $role['scope'] === 'zone')->keys()->all();
    }

    /**
     * @param  array<int, int|string>  $branchIds
     */
    private function syncBranches(Zone $zone, array $branchIds): void
    {
        $ids = array_map('intval', $branchIds);

        Branch::where('company_id', $zone->company_id)->where('zone_id', $zone->id)->whereNotIn('id', $ids)->update(['zone_id' => null]);
        Branch::where('company_id', $zone->company_id)->whereIn('id', $ids)->update(['zone_id' => $zone->id]);
    }

    /**
     * @return Builder<Zone>
     */
    private function zones(): Builder
    {
        return Zone::where('company_id', $this->currentEmployee()->company_id)
            ->with([
                'branches' => fn ($query) => $query->orderBy('id'),
                'employees' => fn ($query) => $query->whereHas('role', fn ($role) => $role->whereIn('key', $this->zoneRoleKeys()))->orderBy('first_name'),
            ])
            ->orderBy('id');
    }
}
