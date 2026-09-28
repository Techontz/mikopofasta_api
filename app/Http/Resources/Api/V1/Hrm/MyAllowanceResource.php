<?php

namespace App\Http\Resources\Api\V1\Hrm;

use App\Models\StaffAllowance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Employee Portal (spec §24 / §60) allowance of the signed-in employee: reason, amount, payroll period and status.
 *
 * @mixin StaffAllowance
 */
class MyAllowanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reason' => $this->reason,
            'reason_label' => ucfirst((string) ($this->reason ?: 'other')).' Allowance',
            'description' => $this->description,
            'amount' => (float) $this->amount,
            'payroll_period' => $this->payroll_period?->format('Y-m'),
            'payroll_period_label' => $this->payroll_period?->format('F Y'),
            'recurring' => (bool) $this->recurring,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'approved_at' => $this->approved_at?->toDateTimeString(),
            'rejection_reason' => $this->rejection_reason,
            'paid_in_payroll' => $this->whenLoaded('payrollRun', fn () => $this->payrollRun?->period->format('F Y')),
            'paid_at' => $this->paid_at?->toDateTimeString(),
            'created_at' => $this->created_at?->toDateString(),
        ];
    }
}
