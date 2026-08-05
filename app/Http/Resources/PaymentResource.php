<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `PaymentSchema` in the frontend's types/repayment.ts.
 * Money goes out as a decimal string — see LoanResource for why.
 *
 * @mixin Payment
 */
final class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'paymentReference' => $this->payment_reference,
            'loanId' => self::id($this->loan_id),
            'customerId' => self::id($this->customer_id),
            'amount' => $this->amount,
            'channel' => $this->channel->value,
            'transactionId' => $this->transaction_id,
            'status' => $this->status->value,
            'branchId' => self::id($this->branch_id),
            'tellerId' => self::id($this->teller_id),
            'receivedAt' => $this->received_at->toIso8601String(),
            'confirmedAt' => $this->confirmed_at?->toIso8601String(),
            'createdBy' => self::id($this->created_by),

            // How this payment reached the books.
            'journalEntryId' => self::id($this->journal_entry_id),
            'journalEntryNumber' => $this->whenLoaded('journalEntry', fn (): ?string => $this->journalEntry?->entry_number),

            'allocations' => PaymentAllocationResource::collection($this->whenLoaded('allocations')),
            'loanNumber' => $this->whenLoaded('loan', fn (): ?string => $this->loan?->loan_number),

            /*
             * Only when the relation is loaded, like `loanNumber` beside it.
             *
             * Added for the cash-deposit screen: a teller selecting which
             * takings a deposit covers needs to recognise the customer, and a
             * payment reference alone is not something anyone recognises.
             */
            'customerName' => $this->whenLoaded('customer', fn (): ?string => $this->customer?->fullName()),
        ];
    }

    private static function id(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
