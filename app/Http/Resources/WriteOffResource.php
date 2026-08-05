<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\WriteOff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A written-off loan and what has come back since.
 *
 * `interestForgone` and `penaltyForgone` are emitted next to the principal
 * because they are NOT part of the ledger posting and a screen that showed only
 * one figure would misstate the debt. The principal is what the books lost; the
 * other two are what the borrower still owed when the business stopped
 * expecting to collect it.
 *
 * @mixin WriteOff
 */
final class WriteOffResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'loanId' => (string) $this->loan_id,
            'loanNumber' => $this->whenLoaded('loan', fn (): ?string => $this->loan?->loan_number),
            'principalWrittenOff' => $this->principal_written_off,
            'interestForgone' => $this->interest_forgone,
            'penaltyForgone' => $this->penalty_forgone,
            'recoveredToDate' => $this->recoveredTotal()->toDecimalString(),
            'outstanding' => $this->outstanding()->toDecimalString(),
            'reason' => $this->reason,
            'journalEntryId' => $this->journal_entry_id === null ? null : (string) $this->journal_entry_id,
            'approvedByName' => $this->whenLoaded('approver', fn (): ?string => $this->approver?->name),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
