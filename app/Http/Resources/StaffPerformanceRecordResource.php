<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\StaffPerformanceRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `StaffPerformanceRecordSchema` in the frontend's types/staff.ts.
 *
 * `targets` and `achieved` go out as the raw maps they are stored as — the
 * metrics differ by role, so there is no fixed shape to project them onto.
 *
 * @mixin StaffPerformanceRecord
 */
final class StaffPerformanceRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'staffProfileId' => (string) $this->staff_profile_id,
            'period' => $this->period,
            'targets' => $this->targets_json,
            'achieved' => $this->achieved_json,
            'rating' => $this->rating?->value,
            'recordedBy' => (string) $this->recorded_by,

            'staffName' => $this->whenLoaded('staffProfile', fn (): string => $this->staffProfile->displayName()),
        ];
    }
}
