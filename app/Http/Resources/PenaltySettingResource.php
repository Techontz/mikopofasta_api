<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PenaltySetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `PenaltySettingSchema` in the frontend's types/loan-charge.ts.
 *
 * @mixin PenaltySetting
 */
final class PenaltySettingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'calculationType' => $this->calculation_type->value,
            'calculationTypeLabel' => $this->calculation_type->label(),
            'amount' => $this->amount,
            'createdAt' => $this->created_at?->toIso8601String(),
            'deletedAt' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
