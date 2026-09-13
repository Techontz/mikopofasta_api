<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DisbursementBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `DisbursementBatchSchema` in the frontend's types/loan.ts.
 *
 * @mixin DisbursementBatch
 */
final class DisbursementBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'loanId' => (string) $this->loan_id,
            'batchReference' => $this->batch_reference,
            'attemptNumber' => $this->attempt_number,
            'channel' => $this->channel->value,
            'status' => $this->status->value,
            'failureReason' => $this->failure_reason,
            'requestedBy' => (string) $this->requested_by,
            'requestedAt' => $this->requested_at->toIso8601String(),
            'completedAt' => $this->completed_at?->toIso8601String(),

            // The money trail: the company account the payout left, and the
            // entry that recorded it (null until the batch succeeds).
            'fundingAccountId' => $this->funding_account_id === null ? null : (string) $this->funding_account_id,
            'fundingAccountCode' => $this->whenLoaded('fundingAccount', fn (): ?string => $this->fundingAccount?->code),
            'fundingAccountName' => $this->whenLoaded('fundingAccount', fn (): ?string => $this->fundingAccount?->name),
            'fundingBankAccountId' => $this->funding_bank_account_id === null ? null : (string) $this->funding_bank_account_id,
            'journalEntryId' => $this->journal_entry_id === null ? null : (string) $this->journal_entry_id,
            'journalEntryNumber' => $this->whenLoaded('journalEntry', fn (): ?string => $this->journalEntry?->entry_number),
            'journalLines' => $this->whenLoaded('journalEntry', fn (): array => $this->journalEntry === null ? [] : $this->journalEntry->lines
                ->map(fn ($line): array => [
                    'accountId' => (string) $line->account_id,
                    'accountCode' => $line->account?->code,
                    'accountName' => $line->account?->name,
                    'debit' => $line->debit_amount,
                    'credit' => $line->credit_amount,
                    'customerId' => $line->customer_id === null ? null : (string) $line->customer_id,
                    'loanId' => $line->loan_id === null ? null : (string) $line->loan_id,
                ])->values()->all()),
        ];
    }
}
