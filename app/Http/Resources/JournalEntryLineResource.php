<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\JournalEntryLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `JournalEntryLineSchema` in the frontend's types/ledger.ts.
 *
 * @mixin JournalEntryLine
 */
final class JournalEntryLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'journalEntryId' => (string) $this->journal_entry_id,
            'accountId' => (string) $this->account_id,
            'debitAmount' => $this->debit_amount,
            'creditAmount' => $this->credit_amount,
            'branchId' => self::id($this->branch_id),
            'customerId' => self::id($this->customer_id),
            'loanId' => self::id($this->loan_id),
            'staffProfileId' => self::id($this->staff_profile_id),

            'accountCode' => $this->whenLoaded('account', fn (): ?string => $this->account?->code),
            'accountName' => $this->whenLoaded('account', fn (): ?string => $this->account?->name),
        ];
    }

    private static function id(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
