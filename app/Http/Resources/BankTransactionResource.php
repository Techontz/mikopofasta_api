<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BankTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `BankTransactionSchema` in the frontend's types/bank.ts, which
 * declares the bank, account and people as printed names.
 *
 * @mixin BankTransaction
 */
final class BankTransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'reference' => $this->reference,
            'date' => $this->transacted_on->toDateString(),

            'bankName' => $this->whenLoaded('bankAccount', fn (): string => $this->bankAccount->bank_name, ''),
            'accountName' => $this->whenLoaded('bankAccount', fn (): string => $this->bankAccount->account_name, ''),
            'accountNumber' => $this->whenLoaded('bankAccount', fn (): string => $this->bankAccount->account_number, ''),
            'branch' => $this->whenLoaded(
                'branch',
                fn (): string => $this->branch_id === null ? '' : $this->branch->name,
                '',
            ),

            'type' => $this->type->value,
            'typeLabel' => $this->type->label(),
            'amount' => $this->amount,

            'requestedBy' => $this->whenLoaded('requester', fn (): string => $this->requester->name, ''),
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),

            'decidedBy' => $this->whenLoaded(
                'decider',
                fn (): ?string => $this->decided_by === null ? null : $this->decider->name,
            ),
            'decidedAt' => $this->decided_at?->toIso8601String(),
            'note' => $this->note,

            'bankAccountId' => (string) $this->bank_account_id,
            'branchId' => $this->branch_id === null ? null : (string) $this->branch_id,
            // Null until approved — the trail from this row to the ledger.
            'journalEntryId' => $this->journal_entry_id === null ? null : (string) $this->journal_entry_id,
        ];
    }
}
