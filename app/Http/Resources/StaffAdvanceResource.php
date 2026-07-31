<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\StaffAdvance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `StaffAdvanceSchema` in the frontend's types/staff.ts.
 *
 * @mixin StaffAdvance
 */
final class StaffAdvanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'staffProfileId' => (string) $this->staff_profile_id,
            'amount' => $this->amount,
            'status' => $this->status->value,
            'requestedAt' => $this->requested_at->toIso8601String(),
            'approvedBy' => $this->approved_by === null ? null : (string) $this->approved_by,
            'approvedAt' => $this->approved_at?->toIso8601String(),
            'disbursedAt' => $this->disbursed_at?->toIso8601String(),
            'journalEntryId' => $this->journal_entry_id === null ? null : (string) $this->journal_entry_id,

            'staffName' => $this->whenLoaded('staffProfile', fn (): string => $this->staffProfile->displayName()),
        ];
    }
}
