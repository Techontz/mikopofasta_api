<?php

namespace App\Http\Resources\Api\V1\Accounting;

use App\Models\AccountingPeriod;
use App\Models\BranchPeriodResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AccountingPeriod
 */
class AccountingPeriodResource extends JsonResource
{
    /**
     * @var list<string>
     */
    private const AMOUNTS = [
        'interest_income', 'reserve_amount', 'salary_advance_income', 'fee_income', 'penalty_income', 'recovery_income', 'total_income', 'expenses',
        'gross_profit', 'loss_brought_forward', 'net_profit', 'loss_carried_forward', 'hq_hold_amount', 'distributable_profit',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'month' => $this->period_start->format('Y-m'),
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'status' => $this->status,
            'closed_by' => $this->whenLoaded('closedBy', fn () => $this->closedBy?->full_name),
            'closed_at' => $this->closed_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
            'results' => $this->whenLoaded('results', fn () => $this->results->map(fn (BranchPeriodResult $result): array => [
                'branch_id' => $result->branch_id,
                'branch' => $result->branch?->name,
                'hq_hold_percent' => (float) $result->hq_hold_percent,
                'commission_eligible' => $result->commission_eligible,
            ] + collect(self::AMOUNTS)->mapWithKeys(fn (string $column): array => [$column => (float) $result->{$column}])->all())),
            'totals' => $this->whenLoaded('results', fn () => collect(self::AMOUNTS)->mapWithKeys(fn (string $column): array => [$column => round((float) $this->results->sum($column), 2)])),
        ];
    }
}
