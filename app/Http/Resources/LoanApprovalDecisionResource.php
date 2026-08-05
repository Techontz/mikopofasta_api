<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LoanApprovalDecision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One decision in a loan's approval trail.
 *
 * The stage name comes from the row's own copy, not from the relation, so a
 * trail read after a stage is renamed still says what it said at the time.
 *
 * @mixin LoanApprovalDecision
 */
final class LoanApprovalDecisionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'stageCode' => $this->stage_code,
            'stageName' => $this->stage_name,
            'decision' => $this->decision->value,
            'decisionLabel' => $this->decision->label(),
            'fromStatus' => $this->from_status->value,
            'toStatus' => $this->to_status->value,
            'reason' => $this->reason,
            'decidedBy' => $this->whenLoaded('decider', fn (): array => [
                'id' => (string) $this->decider->id,
                'name' => $this->decider->name,
            ]),
            'decidedAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
