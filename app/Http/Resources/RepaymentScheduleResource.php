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

            /*
             * What is using this schedule, when the caller asked for it.
             *
             * Settings → Repayment Schedules needs these to explain itself: the
             * frequency is locked once loans exist and the row cannot be retired
             * while a product offers it, and a disabled control with no figure
             * beside it reads as a bug rather than a rule.
             */
            'loanCount' => $this->whenCounted('loans'),
            'productCount' => $this->whenCounted('products'),
        ];
    }
}
