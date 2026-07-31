<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Ward;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `WardSchema` in the frontend's types/branch.ts.
 *
 * @mixin Ward
 */
final class WardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'districtId' => (string) $this->district_id,
            'name' => $this->name,
        ];
    }
}
