<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CapitalContribution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `CapitalContributionSchema` in the frontend's types/capital.ts.
 *
 * @mixin CapitalContribution
 */
final class CapitalContributionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'reference' => $this->reference,
            'shareholderId' => (string) $this->shareholder_id,
            'amount' => $this->amount,
            'payMethod' => $this->pay_method->value,
            'payMethodLabel' => $this->pay_method->label(),
            'receiptNo' => $this->receipt_no,
            'chequeNo' => $this->cheque_no,
            'createdAt' => $this->created_at?->toIso8601String(),
            'journalEntryId' => $this->journal_entry_id === null ? null : (string) $this->journal_entry_id,
            'journalEntryNumber' => $this->whenLoaded('journalEntry', fn (): ?string => $this->journalEntry?->entry_number),

            // The money trail: where it came from, where it landed, who recorded it.
            'sourceAccountName' => $this->source_account_name,
            'sourceAccountNumber' => $this->source_account_number,
            'bankAccountId' => $this->bank_account_id === null ? null : (string) $this->bank_account_id,
            'receivedAccountId' => $this->received_account_id === null ? null : (string) $this->received_account_id,
            'receivedAccountCode' => $this->whenLoaded('receivedAccount', fn (): ?string => $this->receivedAccount?->code),
            'receivedAccountName' => $this->whenLoaded('receivedAccount', fn (): ?string => $this->receivedAccount?->name),
            'recordedBy' => $this->created_by === null ? null : (string) $this->created_by,
            'recordedByName' => $this->whenLoaded('recorder', fn (): ?string => $this->recorder?->name),
            'removedAt' => $this->deleted_at?->toIso8601String(),

            'shareholderName' => $this->whenLoaded('shareholder', fn (): string => $this->shareholder->full_name),
        ];
    }
}
