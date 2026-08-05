<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Recovery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One instalment recovered against a written-off loan.
 *
 * @mixin Recovery
 */
final class RecoveryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'loanId' => (string) $this->loan_id,
            'loanNumber' => $this->whenLoaded('loan', fn (): ?string => $this->loan?->loan_number),
            'writeOffId' => (string) $this->write_off_id,
            'amount' => $this->amount,
            'narrative' => $this->narrative,
            'journalEntryId' => $this->journal_entry_id === null ? null : (string) $this->journal_entry_id,
            'recordedByName' => $this->whenLoaded('recorder', fn (): ?string => $this->recorder?->name),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
