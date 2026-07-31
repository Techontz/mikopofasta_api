<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\FloatTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `FloatTransferSchema` in the frontend's types/capital.ts.
 *
 * @mixin FloatTransfer
 */
final class FloatTransferResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'kind' => $this->kind->value,
            'amount' => $this->amount,
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            'rejectionReason' => $this->rejection_reason,
            'createdAt' => $this->created_at?->toIso8601String(),
            'approvedAt' => $this->approved_at?->toIso8601String(),
            'journalEntryId' => $this->journal_entry_id === null ? null : (string) $this->journal_entry_id,
            'requestedBy' => (string) $this->requested_by,

            'fromBranchName' => $this->whenLoaded('fromBranch', fn (): ?string => $this->fromBranch?->name),
            'toBranchName' => $this->whenLoaded('toBranch', fn (): ?string => $this->toBranch?->name),
            'fromAccountName' => $this->whenLoaded('fromAccount', fn (): ?string => $this->fromAccount?->name),
            'toAccountName' => $this->whenLoaded('toAccount', fn (): ?string => $this->toAccount?->name),
            'requesterName' => $this->whenLoaded('requester', fn (): ?string => $this->requester?->name),
            'approverName' => $this->whenLoaded('approver', fn (): ?string => $this->approver?->name),
        ];
    }
}
