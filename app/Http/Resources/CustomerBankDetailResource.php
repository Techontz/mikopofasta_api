<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CustomerBankDetail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `CustomerBankDetailsSchema` in the frontend's types/customer.ts.
 *
 * @mixin CustomerBankDetail
 */
final class CustomerBankDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'customerId' => (string) $this->customer_id,
            'bankName' => $this->bank_name,
            'accountNumber' => $this->account_number,
            'accountName' => $this->account_name,
            'checkNumber' => $this->check_number,
            'phoneNumber' => $this->phone_number,
        ];
    }
}
