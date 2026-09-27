<?php

namespace App\Http\Resources\Api\V1\Bank;

use App\Models\BankAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BankAccount
 */
class BankAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'balance' => $this->when($request->boolean('with_balance') || $request->routeIs('api.v1.bank.balances'), fn (): float => $this->balance()),
        ];
    }
}
