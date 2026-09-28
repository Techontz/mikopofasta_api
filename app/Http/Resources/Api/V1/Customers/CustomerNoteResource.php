<?php

namespace App\Http\Resources\Api\V1\Customers;

use App\Models\CustomerNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerNote
 */
class CustomerNoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customerId' => (int) $this->customer_id,
            'body' => $this->body,
            'createdById' => $this->created_by === null ? null : (int) $this->created_by,
            'createdByName' => $this->whenLoaded('author', fn () => $this->author?->full_name, null),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
