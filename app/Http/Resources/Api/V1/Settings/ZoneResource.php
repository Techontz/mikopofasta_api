<?php

namespace App\Http\Resources\Api\V1\Settings;

use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Zone
 */
class ZoneResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'branches' => $this->whenLoaded('branches', fn () => $this->branches->map(fn ($branch): array => ['id' => $branch->id, 'name' => $branch->name])->values()),
            'managers' => $this->whenLoaded('employees', fn () => $this->employees->map(fn ($employee): array => ['id' => $employee->id, 'name' => $employee->full_name])->values()),
            'created_at' => $this->created_at?->toDateString(),
        ];
    }
}
