<?php

namespace App\Http\Resources\Api\V1\SalaryAdvance;

use App\Models\SalaryAdvanceCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SalaryAdvanceCategory
 */
class SalaryAdvanceCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'interest_rate' => (float) $this->interest_rate,
            'amount_from' => (float) $this->amount_from,
            'amount_to' => (float) $this->amount_to,
            'fee' => (float) $this->fee,
        ];
    }
}
