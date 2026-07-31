<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\JournalEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `JournalEntrySchema` in the frontend's types/ledger.ts.
 *
 * @mixin JournalEntry
 */
final class JournalEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'entryNumber' => $this->entry_number,
            'entryDate' => $this->entry_date->toDateString(),
            'description' => $this->description,
            'sourceType' => $this->source_type->value,
            'sourceId' => $this->source_id === null ? null : (string) $this->source_id,
            'isReversal' => $this->is_reversal,
            'reversedEntryId' => $this->reversed_entry_id === null ? null : (string) $this->reversed_entry_id,
            'createdBy' => (string) $this->created_by,
            'postedAt' => $this->posted_at->toIso8601String(),

            'lines' => JournalEntryLineResource::collection($this->whenLoaded('lines')),
            'totalDebits' => $this->whenLoaded('lines', fn (): string => $this->totalDebits()->toDecimalString()),
            'totalCredits' => $this->whenLoaded('lines', fn (): string => $this->totalCredits()->toDecimalString()),
            'balanced' => $this->whenLoaded('lines', fn (): bool => $this->isBalanced()),
        ];
    }
}
