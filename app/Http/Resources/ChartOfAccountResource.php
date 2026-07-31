<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ChartOfAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `ChartOfAccountSchema` in the frontend's types/ledger.ts, plus the
 * cached balance the accounts screen shows.
 *
 * @mixin ChartOfAccount
 */
final class ChartOfAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type->value,
            'typeLabel' => $this->type->label(),
            'parentAccountId' => $this->parent_account_id === null ? null : (string) $this->parent_account_id,
            'isSystem' => $this->is_system,
            'branchId' => $this->branch_id === null ? null : (string) $this->branch_id,
            'status' => $this->status->value,
            'deletedAt' => $this->deleted_at?->toIso8601String(),

            'balance' => $this->whenLoaded('balances', fn (): string => $this->cachedBalance()->toDecimalString()),
        ];
    }
}
