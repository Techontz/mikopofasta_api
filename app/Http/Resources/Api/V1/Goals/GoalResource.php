<?php

namespace App\Http\Resources\Api\V1\Goals;

use App\Models\Goal;
use App\Services\Goals\GoalProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Goal
 */
class GoalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $assignee = match ($this->scope_type) {
            'branch' => $this->branch?->name,
            'zone' => $this->zone?->name,
            'employee' => $this->employee?->full_name,
            default => 'ALL BRANCHES',
        };

        return [
            'id' => $this->id,
            'title' => $this->title,
            'scope_type' => $this->scope_type,
            'scope_label' => Goal::SCOPES[$this->scope_type] ?? $this->scope_type,
            'branch_id' => $this->branch_id,
            'zone_id' => $this->zone_id,
            'employee_id' => $this->employee_id,
            'assignee' => $assignee,
            'metric' => $this->metric,
            'metric_label' => Goal::METRICS[$this->metric] ?? $this->metric,
            'is_money' => in_array($this->metric, Goal::MONEY_METRICS, true),
            'target' => (float) $this->target,
            'period_type' => $this->period_type,
            'period_label' => Goal::PERIODS[$this->period_type] ?? $this->period_type,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'notes' => $this->notes,
            'created_by' => $this->creator?->full_name,
            'progress' => app(GoalProgress::class)->summary($this->resource),
        ];
    }
}
