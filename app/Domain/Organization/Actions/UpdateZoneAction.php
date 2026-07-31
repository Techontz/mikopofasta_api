<?php

declare(strict_types=1);

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\DTOs\ZoneData;
use App\Enums\AuditAction;
use App\Models\User;
use App\Models\Zone;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Updates a zone (PUT /zones/{zone}).
 */
final class UpdateZoneAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Zone $zone, ZoneData $data, User $actor): Zone
    {
        return DB::transaction(function () use ($zone, $data, $actor): Zone {
            $before = ['name' => $zone->name, 'zone_manager_id' => $zone->zone_manager_id];

            $zone->update([
                'name' => $data->name,
                'zone_manager_id' => $data->zoneManagerId,
            ]);

            $this->audit->log(
                AuditAction::ZoneUpdated,
                $zone,
                before: $before,
                after: ['name' => $zone->name, 'zone_manager_id' => $zone->zone_manager_id],
                actor: $actor,
            );

            return $zone->load('manager');
        });
    }
}
