<?php

namespace App\Http\Resources\Api\V1\Visa;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Customer
 */
class VisaCustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch' => $this->branch?->name,
            'customer' => $this->full_name,
            'phone' => $this->phone,
            'bank_account_name' => $this->bank_account_name,
            'bank_password' => $this->bank_password,
        ];
    }
}
