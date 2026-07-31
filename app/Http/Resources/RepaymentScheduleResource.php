<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\RepaymentSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `RepaymentScheduleSchema` in the frontend's types/loan-product.ts.
 *
 * @mixin RepaymentSchedule
 */
final class RepaymentScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'frequencyDays' => $this->frequency_days,
            'deletedAt' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
