<?php

namespace App\Http\Resources\Api\V1\Savings;

use App\Models\Saving;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Saving
 */
class SavingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch' => $this->branch?->name,
            'customer_id' => $this->customer_id,
            'customer' => $this->customer?->full_name,
            'type' => $this->type,
            'withdrawal_type' => $this->withdrawal_type,
            'description' => $this->description,
            'amount' => (float) $this->amount,
            'transaction_date' => $this->transaction_date->toDateString(),
            'reversed' => $this->reversed_at !== null,
            'reversal_reason' => $this->reversal_reason,
        ];
    }
}
