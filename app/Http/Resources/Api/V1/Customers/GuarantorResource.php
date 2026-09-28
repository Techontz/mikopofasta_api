<?php

namespace App\Http\Resources\Api\V1\Customers;

use App\Models\Guarantor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Guarantor
 */
class GuarantorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customerId' => (int) $this->customer_id,
            'loanId' => $this->loan_id === null ? null : (int) $this->loan_id,
            'name' => $this->name ?? trim(implode(' ', array_filter([$this->first_name, $this->middle_name, $this->last_name]))),
            'phone' => $this->phone,
            'nidaNumber' => $this->nida_number,
            'relationship' => $this->relationship,
            'address' => $this->address,
            'occupation' => $this->occupation,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
