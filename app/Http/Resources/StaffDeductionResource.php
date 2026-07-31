<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\StaffDeduction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A penalty recorded against somebody's pay.
 *
 * `reason` is not optional on this resource because it is not optional on the
 * table: a deduction from someone's salary that nobody explained is the kind of
 * thing that has to be defensible a year later.
 *
 * @mixin StaffDeduction
 */
final class StaffDeductionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'staffProfileId' => (string) $this->staff_profile_id,
            'type' => $this->type->value,
            'amount' => $this->amount,
            'period' => $this->period,
            'reason' => $this->reason,
            'createdAt' => $this->created_at?->toIso8601String(),
            'createdByName' => $this->whenLoaded('creator', fn (): ?string => $this->creator?->name),
        ];
    }
}
