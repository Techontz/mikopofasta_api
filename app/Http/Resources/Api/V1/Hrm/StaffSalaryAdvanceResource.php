<?php

namespace App\Http\Resources\Api\V1\Hrm;

use App\Enums\StaffCreditStatus;
use App\Models\StaffSalaryAdvance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StaffSalaryAdvance
 */
class StaffSalaryAdvanceResource extends JsonResource
{
    use PresentsStaffCreditWorkflow;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => $this->employee?->full_name),
            'category' => $this->whenLoaded('category', fn () => $this->category?->name),
            'amount' => (float) $this->amount,
            'fee' => (float) $this->fee,
            'recovered_amount' => (float) $this->recovered_amount,
            'outstanding_amount' => in_array($this->status, StaffCreditStatus::recovering(), true) ? $this->outstandingAmount() : 0.0,
            'source_account' => $this->source_account,
            ...$this->workflow($request),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
