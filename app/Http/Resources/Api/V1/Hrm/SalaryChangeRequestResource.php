<?php

namespace App\Http\Resources\Api\V1\Hrm;

use App\Models\Employee;
use App\Models\SalaryChangeRequest;
use App\Services\Hrm\SalaryChanges;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SalaryChangeRequest
 */
class SalaryChangeRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => $this->employee?->full_name),
            'current_values' => $this->current_values,
            'proposed_values' => $this->proposed_values,
            'current_salary' => isset($this->current_values['salary']) ? (float) $this->current_values['salary'] : null,
            'proposed_salary' => (float) ($this->proposed_values['salary'] ?? 0),
            'status' => $this->status,
            'approval_stage' => $this->approval_stage,
            'reason' => $this->reason,
            'requested_by' => $this->requested_by,
            'requested_by_name' => $this->whenLoaded('requester', fn () => $this->requester?->full_name),
            'approved_by' => $this->approved_by,
            'approved_by_name' => $this->whenLoaded('approver', fn () => $this->approver?->full_name),
            'approved_at' => $this->approved_at?->toDateTimeString(),
            'rejected_by' => $this->rejected_by,
            'rejected_by_name' => $this->whenLoaded('rejecter', fn () => $this->rejecter?->full_name),
            'rejected_at' => $this->rejected_at?->toDateTimeString(),
            'rejection_reason' => $this->rejection_reason,
            ...($viewer instanceof Employee ? app(SalaryChanges::class)->flags($this->resource, $viewer) : ['can_approve' => false, 'approve_blocked_reason' => null]),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
