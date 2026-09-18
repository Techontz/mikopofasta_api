<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CustomerAdvancePayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One collection on the Salary Advance Repayment screen.
 *
 * The three portions are what the journal entry credited, not a re-derivation:
 * this row is the transaction history the client asked to keep beside the
 * dashboard summary, so it has to be able to answer "where did the 20,000 go"
 * years later.
 *
 * @mixin CustomerAdvancePayment
 */
final class CustomerAdvancePaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'reference' => $this->reference,
            'advanceId' => (string) $this->customer_advance_id,
            'advanceReference' => $this->whenLoaded('advance', fn (): string => $this->advance->reference, ''),
            'customerName' => $this->whenLoaded(
                'advance',
                fn (): string => $this->advance->relationLoaded('customer') && $this->advance->customer !== null
                    ? $this->advance->customer->fullName()
                    : '',
                '',
            ),
            'branchId' => $this->branch_id === null ? null : (string) $this->branch_id,

            'amount' => $this->amount,
            'principalPortion' => $this->principal_portion,
            'interestPortion' => $this->interest_portion,
            'feePortion' => $this->fee_portion,
            // The client's second bucket: interest and fee together.
            'profitPortion' => $this->profitMoney()->toDecimalString(),

            'channel' => $this->channel,
            'note' => $this->note,
            'paidAt' => $this->paid_at->toIso8601String(),
            'date' => $this->paid_at->toDateString(),
            'journalEntryId' => $this->journal_entry_id === null ? null : (string) $this->journal_entry_id,
            'reversed' => $this->isReversed(),
            'reversedAt' => $this->reversed_at?->toIso8601String(),
        ];
    }
}
