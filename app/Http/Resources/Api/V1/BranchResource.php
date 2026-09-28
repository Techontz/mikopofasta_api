<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Branch
 */
class BranchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'type' => $this->type,
            'status' => $this->status,
            'region_id' => $this->region_id,
            'region' => $this->whenLoaded('region', fn () => $this->region?->name),
            'zone_id' => $this->zone_id,
            'zone' => $this->whenLoaded('zone', fn () => $this->zone?->name),
            'customer_counts' => $this->when(isset($this->all_count), fn () => [
                'active' => (int) $this->active_count,
                'pending' => (int) $this->pending_count,
                'default' => (int) $this->default_count,
                'done' => (int) $this->done_count,
                'all' => (int) $this->all_count,
            ]),
        ];
    }
}
