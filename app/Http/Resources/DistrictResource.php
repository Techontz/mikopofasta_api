<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\District;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `DistrictSchema` in the frontend's types/branch.ts.
 *
 * @mixin District
 */
final class DistrictResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'regionId' => (string) $this->region_id,
            'name' => $this->name,
        ];
    }
}
