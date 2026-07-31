<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\GroupMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `GroupMemberSchema` in the frontend's types/group.ts.
 *
 * @mixin GroupMember
 */
final class GroupMemberResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'customerId' => (string) $this->customer_id,
            'customerName' => $this->whenLoaded('customer', fn (): string => $this->customer->fullName()),
            'customerNumber' => $this->whenLoaded('customer', fn (): ?string => $this->customer->customer_number),
            'phone' => $this->whenLoaded('customer', fn (): ?string => $this->customer->phone),
            'role' => $this->role->value,
            'roleLabel' => $this->role->label(),
            'joinedAt' => $this->joined_at->toDateString(),
            'status' => $this->status,
        ];
    }
}
