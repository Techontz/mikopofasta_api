<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CommissionDistribution;
use App\Models\CommissionPool;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `CommissionPoolSchema` in the frontend's types/commission.ts.
 *
 * @mixin CommissionPool
 */
final class CommissionPoolResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'branchId' => (string) $this->branch_id,
            'period' => $this->period,
            'branchProfit' => $this->branch_profit,
            'lossCarryForward' => $this->loss_carry_forward,
            'hqHoldAmount' => $this->hq_hold_amount,
            'distributableProfit' => $this->distributable_profit,
            'poolPercentage' => $this->pool_percentage,
            'poolAmount' => $this->pool_amount,

            // §11's hard rule, stated in the payload so the commission screen
            // shows "Blocked" for the same reason the service refuses.
            'distributable' => $this->isDistributable(),

            'branchName' => $this->whenLoaded('branch', fn (): ?string => $this->branch?->name),

            'distributions' => $this->whenLoaded('distributions', fn (): array => $this->distributions
                ->map(fn (CommissionDistribution $d): array => [
                    'id' => (string) $d->id,
                    'commissionPoolId' => (string) $d->commission_pool_id,
                    'staffProfileId' => (string) $d->staff_profile_id,
                    'shareAmount' => $d->share_amount,
                    'staffName' => $d->relationLoaded('staffProfile')
                        ? $d->staffProfile->displayName()
                        : null,
                ])->all()),
        ];
    }
}
