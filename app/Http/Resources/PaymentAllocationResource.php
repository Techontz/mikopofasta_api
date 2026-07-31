<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PaymentAllocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `PaymentAllocationSchema` — one row per installment a payment
 * touched, in Penalty → Interest → Principal order.
 *
 * @mixin PaymentAllocation
 */
final class PaymentAllocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'paymentId' => (string) $this->payment_id,
            'loanScheduleId' => (string) $this->loan_schedule_id,
            'penaltyAllocated' => $this->penalty_allocated,
            'interestAllocated' => $this->interest_allocated,
            'principalAllocated' => $this->principal_allocated,
            'total' => $this->total()->toDecimalString(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
