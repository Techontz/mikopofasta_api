<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\NextOfKin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `NextOfKinSchema` in the frontend's types/next-of-kin.ts.
 *
 * @mixin NextOfKin
 */
final class NextOfKinResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'customerId' => (string) $this->customer_id,
            'name' => $this->name,
            'relationship' => $this->relationship->value,
            'phone' => $this->phone,
            'address' => $this->address,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
