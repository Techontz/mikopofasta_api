<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\StaffLoan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `StaffLoanSchema` in the frontend's types/staff.ts.
 *
 * @mixin StaffLoan
 */
final class StaffLoanResource extends JsonResource
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
            'disbursedAt' => $this->disbursed_at->toDateString(),
            'journalEntryId' => (string) $this->journal_entry_id,

            'staffName' => $this->whenLoaded('staffProfile', fn (): string => $this->staffProfile->displayName()),
        ];
    }
}
