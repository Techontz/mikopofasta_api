<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\HqAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of the seven headquarters accounts.
 *
 * Matches the shape `HqAccountBalanceTable` reads — the frontend has been
 * drawing these from a hardcoded `LEGACY_HQ_ACCOUNTS` constant, which was
 * right while there was no endpoint and is wrong now that a balance can move.
 *
 * @mixin HqAccount
 */
final class HqAccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            // Upper case as the legacy system holds it; printed verbatim.
            'name' => $this->name,
            'balance' => $this->balance,
        ];
    }
}
