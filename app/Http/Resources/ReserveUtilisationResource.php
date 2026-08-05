<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ReserveUtilisation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One request to spend the Reserve — Decision Register D1.
 *
 * @mixin ReserveUtilisation
 */
final class ReserveUtilisationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'reference' => $this->reference,
            'purpose' => $this->purpose->value,
            'purposeLabel' => $this->purpose->label(),
            'amount' => $this->amount,
            'narrative' => $this->narrative,
            'status' => $this->status->value,
            'decisionReason' => $this->decision_reason,
            'createdAt' => $this->created_at?->toIso8601String(),
            'approvedAt' => $this->approved_at?->toIso8601String(),
            // Null while pending or rejected: reserve moves on approval, so its
            // absence is how a screen knows nothing has left the fund.
            'journalEntryId' => $this->journal_entry_id === null ? null : (string) $this->journal_entry_id,
            'requestedBy' => (string) $this->requested_by,

            'targetBranchName' => $this->whenLoaded('targetBranch', fn (): ?string => $this->targetBranch?->name),
            'requesterName' => $this->whenLoaded('requester', fn (): ?string => $this->requester?->name),
            'approverName' => $this->whenLoaded('approver', fn (): ?string => $this->approver?->name),
        ];
    }
}
