<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CashDeposit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A teller's banked cash, awaiting or carrying Finance's confirmation.
 *
 * @mixin CashDeposit
 */
final class CashDepositResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'branchId' => (string) $this->branch_id,
            'branchName' => $this->whenLoaded('branch', fn (): ?string => $this->branch?->name),
            'bankAccountId' => (string) $this->bank_account_id,
            'bankAccountName' => $this->whenLoaded('bankAccount', fn (): ?string => $this->bankAccount?->account_name),
            'tellerName' => $this->whenLoaded('teller', fn (): ?string => $this->teller?->name),
            'amount' => $this->amount,
            'status' => $this->status->value,
            'paymentIds' => array_map('strval', $this->matched_payment_ids ?? []),

            // Whether a slip exists, never where it is: the path is on a
            // private disk (§1) and a URL in the payload would invite a caller
            // to try fetching it directly.
            'hasSlip' => $this->deposit_slip_path !== null,

            'reconciledAt' => $this->reconciled_at?->toIso8601String(),
            'journalEntryId' => $this->journal_entry_id === null ? null : (string) $this->journal_entry_id,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
