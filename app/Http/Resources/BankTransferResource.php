<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BankTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `BankTransferSchema` in the frontend's types/bank.ts.
 *
 * `toAccount` is a single printed string on that schema, so the two possible
 * destinations — a branch or another bank account — are collapsed into one
 * name here. The ids are alongside for anything that needs to know which it
 * actually was.
 *
 * @mixin BankTransfer
 */
final class BankTransferResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'reference' => $this->reference,
            'kind' => $this->kind->value,
            'kindLabel' => $this->kind->label(),

            'fromAccount' => $this->whenLoaded(
                'fromAccount',
                fn (): string => $this->fromAccount->account_name,
                '',
            ),
            'toAccount' => $this->destinationName(),

            'amount' => $this->amount,
            'chargeFee' => $this->charge_fee,
            'reason' => $this->reason,
            'description' => $this->description,
            'date' => $this->transferred_on->toDateString(),
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            'requestedBy' => $this->whenLoaded('requester', fn (): string => $this->requester->name, ''),

            'fromAccountId' => (string) $this->from_account_id,
            'toAccountId' => $this->to_account_id === null ? null : (string) $this->to_account_id,
            'toBranchId' => $this->to_branch_id === null ? null : (string) $this->to_branch_id,
            'journalEntryId' => $this->journal_entry_id === null ? null : (string) $this->journal_entry_id,
        ];
    }

    /** Whichever destination this kind of transfer has. */
    private function destinationName(): string
    {
        if ($this->to_branch_id !== null && $this->relationLoaded('toBranch')) {
            return $this->toBranch->name;
        }

        if ($this->to_account_id !== null && $this->relationLoaded('toAccount')) {
            return $this->toAccount->account_name;
        }

        return '';
    }
}
