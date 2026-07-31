<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Street;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `StreetSchema` in the frontend's types/branch.ts.
 *
 * @mixin Street
 */
final class StreetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'wardId' => (string) $this->ward_id,
            'name' => $this->name,
        ];
    }
}
