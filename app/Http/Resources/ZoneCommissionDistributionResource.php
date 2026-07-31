<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ZoneCommissionDistribution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `ZoneCommissionDistributionSchema` in types/commission.ts.
 *
 * `journalEntryId` is nullable here where §2.9 types it NN: the override is
 * expensed on the zone manager's payroll entry, which does not exist until
 * that run is finalized.
 *
 * @mixin ZoneCommissionDistribution
 */
final class ZoneCommissionDistributionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'zoneId' => (string) $this->zone_id,
            'period' => $this->period,
            'totalPoolBase' => $this->total_pool_base,
            'overridePercentage' => $this->override_percentage,
            'overrideAmount' => $this->override_amount,
            'journalEntryId' => $this->journal_entry_id === null ? null : (string) $this->journal_entry_id,

            'zoneName' => $this->whenLoaded('zone', fn (): ?string => $this->zone?->name),
        ];
    }
}
