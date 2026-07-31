<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\StaffAllowance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An allowance an employee is entitled to draw.
 *
 * Distinct from the `allowances` items on a payroll line, which are what a
 * payslip actually paid. Both appear on the staff detail screen and they answer
 * different questions: this one is "what do they get", that one is "what did
 * they get in June".
 *
 * @mixin StaffAllowance
 */
final class StaffAllowanceResource extends JsonResource
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

            // Null means recurring. The flag is emitted beside it so a screen
            // does not have to know that null carries that meaning.
            'period' => $this->period,
            'recurring' => $this->isRecurring(),

            'reason' => $this->reason,
            'active' => $this->active,
            'createdAt' => $this->created_at?->toIso8601String(),
            'createdByName' => $this->whenLoaded('creator', fn (): ?string => $this->creator?->name),
        ];
    }
}
