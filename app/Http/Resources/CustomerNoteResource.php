<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CustomerNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `CustomerNoteSchema` in the frontend's types/customer-note.ts.
 *
 * @mixin CustomerNote
 */
final class CustomerNoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'customerId' => (string) $this->customer_id,
            'authorId' => (string) $this->author_id,
            'authorName' => $this->whenLoaded('author', fn (): ?string => $this->author?->name),
            'note' => $this->note,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
