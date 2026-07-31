<?php

declare(strict_types=1);

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Exceptions\OrganizationInUseException;
use App\Enums\AuditAction;
use App\Models\User;
use App\Models\Zone;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Soft-deletes a zone (DELETE /zones/{zone}).
 *
 * The branch guard mirrors the frontend's deleteZone. The user guard exists
 * because `users.zone_id` is RESTRICT on delete — a Zone Manager scoped here
 * would otherwise turn the request into a 500.
 */
final class DeleteZoneAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Zone $zone, User $actor): void
    {
        if ($zone->branches()->exists()) {
            throw OrganizationInUseException::zoneHasBranches();
        }

        $users = $zone->users()->count();

        if ($users > 0) {
            throw OrganizationInUseException::zoneHasUsers($users);
        }

        DB::transaction(function () use ($zone, $actor): void {
            $this->audit->log(
                AuditAction::ZoneDeleted,
                $zone,
                before: ['name' => $zone->name, 'zone_manager_id' => $zone->zone_manager_id],
                actor: $actor,
            );

            $zone->delete();
        });
    }
}
