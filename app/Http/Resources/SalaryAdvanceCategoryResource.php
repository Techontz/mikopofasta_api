<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SalaryAdvanceCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `SalaryAdvanceCategorySchema` in the frontend's
 * types/salary-advance.ts, plus the recovery term.
 *
 * `recoveryPeriods` is not on that schema because the fixture the screen ran on
 * had no notion of a repayment schedule. It is what turns a band into terms
 * rather than a price list, so it is emitted and the screen shows it.
 *
 * @mixin SalaryAdvanceCategory
 */
final class SalaryAdvanceCategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'interestRate' => $this->interest_rate,
            'fromAmount' => $this->from_amount,
            'toAmount' => $this->to_amount,
            'chargeFee' => $this->charge_fee,
            'recoveryPeriods' => $this->recovery_periods,

            // How many advances have been priced by this band — what makes the
            // delete confirmation able to say whether it is in use.
            'advanceCount' => $this->whenCounted('advances'),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
