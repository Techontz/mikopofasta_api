<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SuspenseItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `SuspenseItemSchema` in the frontend's types/repayment.ts.
 *
 * @mixin SuspenseItem
 */
final class SuspenseItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'paymentId' => (string) $this->payment_id,
            'reason' => $this->reason,
            'amount' => $this->amount,
            'status' => $this->status->value,
            'resolvedBy' => $this->resolved_by === null ? null : (string) $this->resolved_by,
            'resolvedAt' => $this->resolved_at?->toIso8601String(),

            'paymentReference' => $this->whenLoaded('payment', fn (): ?string => $this->payment?->payment_reference),
            'resolvedByName' => $this->whenLoaded('resolver', fn (): ?string => $this->resolver?->name),
        ];
    }
}
