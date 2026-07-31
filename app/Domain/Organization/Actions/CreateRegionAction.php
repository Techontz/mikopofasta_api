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
 * Creates a region (POST /regions).
 */
final class CreateRegionAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(RegionData $data, User $actor): Region
    {
        return DB::transaction(function () use ($data, $actor): Region {
            $region = Region::query()->create(['name' => $data->name]);

            $this->audit->log(
                AuditAction::RegionCreated,
                $region,
                after: ['name' => $region->name],
                actor: $actor,
            );

            return $region;
        });
    }
}
