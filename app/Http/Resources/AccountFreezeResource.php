<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AccountFreeze;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `AccountFreezeSchema` in the frontend's types/audit.ts.
 *
 * @mixin AccountFreeze
 */
final class AccountFreezeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'freezableType' => $this->freezable_type->value,
            'freezableId' => (string) $this->freezable_id,
            'reason' => $this->reason,
            'frozenBy' => (string) $this->frozen_by,
            'frozenAt' => $this->frozen_at->toIso8601String(),
            'unfrozenBy' => $this->unfrozen_by === null ? null : (string) $this->unfrozen_by,
            'unfrozenAt' => $this->unfrozen_at?->toIso8601String(),
        ];
    }
}
