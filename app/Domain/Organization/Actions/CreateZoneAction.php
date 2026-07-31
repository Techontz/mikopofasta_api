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
 * Creates a zone (POST /zones).
 */
final class CreateZoneAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(ZoneData $data, User $actor): Zone
    {
        return DB::transaction(function () use ($data, $actor): Zone {
            $zone = Zone::query()->create([
                'name' => $data->name,
                'zone_manager_id' => $data->zoneManagerId,
            ]);

            $this->audit->log(
                AuditAction::ZoneCreated,
                $zone,
                after: ['name' => $zone->name, 'zone_manager_id' => $zone->zone_manager_id],
                actor: $actor,
            );

            return $zone->load('manager');
        });
    }
}
