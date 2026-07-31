<?php

declare(strict_types=1);

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\DTOs\RegionData;
use App\Enums\AuditAction;
use App\Models\Region;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Updates a region (PUT /regions/{region}).
 */
final class UpdateRegionAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Region $region, RegionData $data, User $actor): Region
    {
        return DB::transaction(function () use ($region, $data, $actor): Region {
            $before = ['name' => $region->name];

            $region->update(['name' => $data->name]);

            $this->audit->log(
                AuditAction::RegionUpdated,
                $region,
                before: $before,
                after: ['name' => $region->name],
                actor: $actor,
            );

            return $region;
        });
    }
}
