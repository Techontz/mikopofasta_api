<?php

namespace App\Http\Resources\Api\V1\Crm;

use App\Models\CrmInteraction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CrmInteraction
 */
class InteractionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $followUp = $this->follow_up_date?->toDateString();

        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => CrmInteraction::TYPES[$this->type] ?? strtoupper($this->type),
            'direction' => $this->direction,
            'direction_label' => CrmInteraction::DIRECTIONS[$this->direction] ?? strtoupper($this->direction),
            'phone' => $this->phone,
            'outcome' => $this->outcome,
            'outcome_label' => $this->outcome ? (CrmInteraction::OUTCOMES[$this->outcome] ?? $this->outcome) : null,
            'notes' => $this->notes,
            'follow_up_date' => $followUp,
            'follow_up_done_at' => $this->follow_up_done_at?->format('Y-m-d H:i'),
            'follow_up_notes' => $this->follow_up_notes,
            'follow_up_status' => match (true) {
                $followUp === null => null,
                $this->follow_up_done_at !== null => 'done',
                $followUp < now()->toDateString() => 'overdue',
                $followUp === now()->toDateString() => 'due',
                default => 'upcoming',
            },
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->full_name,
                'code' => $this->customer->customer_code,
                'phone' => $this->customer->phone,
            ]),
            'branch' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'employee' => $this->whenLoaded('employee', fn () => $this->employee?->full_name),
            'employee_id' => $this->employee_id,
            'created_at' => $this->created_at?->format('Y-m-d H:i'),
        ];
    }
}
