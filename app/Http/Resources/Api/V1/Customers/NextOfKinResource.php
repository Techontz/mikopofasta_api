<?php

namespace App\Http\Resources\Api\V1\Customers;

use App\Models\CustomerNextOfKin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerNextOfKin
 */
class NextOfKinResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customerId' => (int) $this->customer_id,
            'name' => $this->name ?? trim(implode(' ', array_filter([$this->first_name, $this->middle_name, $this->last_name]))),
            'relationship' => $this->relationship,
            'phone' => $this->phone,
            'address' => $this->address,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
