<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `ZoneSchema` in the frontend's types/branch.ts.
 *
 * @mixin Zone
 */
final class ZoneResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'zoneManagerId' => $this->zone_manager_id === null ? null : (string) $this->zone_manager_id,
            'deletedAt' => $this->deleted_at?->toIso8601String(),

            'zoneManagerName' => $this->whenLoaded('manager', fn (): ?string => $this->manager?->name),
            'branchCount' => $this->whenCounted('branches'),
        ];
    }
}
