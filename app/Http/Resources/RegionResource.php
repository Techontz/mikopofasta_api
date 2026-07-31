<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Region;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `RegionSchema` in the frontend's types/branch.ts — id and name only.
 *
 * @mixin Region
 */
final class RegionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'branchCount' => $this->whenCounted('branches'),
        ];
    }
}
