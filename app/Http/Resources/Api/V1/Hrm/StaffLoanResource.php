<?php

namespace App\Http\Resources\Api\V1\Hrm;

use App\Models\StaffLoan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StaffLoan
 */
class StaffLoanResource extends JsonResource
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
            'amount_applied' => (float) $this->amount_applied,
            'amount_approved' => (float) $this->amount_approved,
            'duration' => $this->duration,
            'sessions' => $this->sessions,
            'total_payable' => (float) $this->total_payable,
            'restoration' => (float) $this->restoration,
            'fee' => (float) $this->fee,
            'paid_amount' => $this->paidAmount(),
            'remaining_amount' => $this->remainingAmount(),
            'reason' => $this->reason,
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($payment): array => ['id' => $payment->id, 'amount' => (float) $payment->amount, 'paid_on' => $payment->paid_on?->toDateString()])),
            ...$this->workflow($request),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
