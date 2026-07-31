<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ReserveSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `ReserveSettingSchema` in the frontend's types/loan-charge.ts.
 *
 * @mixin ReserveSetting
 */
final class ReserveSettingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'percentage' => $this->percentage,
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
