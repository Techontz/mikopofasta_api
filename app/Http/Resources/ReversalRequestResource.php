<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ReversalRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `ReversalRequestSchema` in the frontend's types/ledger.ts.
 *
 * @mixin ReversalRequest
 */
final class ReversalRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'journalEntryId' => (string) $this->journal_entry_id,
            'requestedBy' => (string) $this->requested_by,
            'reason' => $this->reason,
            'approvedBy' => $this->approved_by === null ? null : (string) $this->approved_by,
            'status' => $this->status->value,
            'decidedAt' => $this->decided_at?->toIso8601String(),
            'decisionNote' => $this->decision_note,
            'reversalEntryId' => $this->reversal_entry_id === null ? null : (string) $this->reversal_entry_id,

            'entryNumber' => $this->whenLoaded('journalEntry', fn (): ?string => $this->journalEntry?->entry_number),
            'reversalEntryNumber' => $this->whenLoaded('reversalEntry', fn (): ?string => $this->reversalEntry?->entry_number),
        ];
    }
}
