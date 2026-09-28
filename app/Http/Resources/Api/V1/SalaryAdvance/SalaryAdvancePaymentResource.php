<?php

namespace App\Http\Resources\Api\V1\SalaryAdvance;

use App\Models\SalaryAdvancePayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SalaryAdvancePayment
 */
class SalaryAdvancePaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch' => $this->salaryAdvance?->branch?->name,
            'customer' => $this->salaryAdvance?->customer?->full_name,
            'amount' => (float) $this->amount,
            'paid_on' => $this->paid_on->toDateString(),
        ];
    }
}
