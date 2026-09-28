<?php

namespace App\Http\Resources\Api\V1\Payments;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receipt_number' => $this->receipt_number,
            'source' => $this->source,
            'channel' => $this->channel,
            'provider' => $this->provider ?: null,
            'reference' => $this->reference,
            'transaction_id' => $this->transaction_id,
            'phone' => $this->phone,
            'amount' => (float) $this->amount,
            'allocated_amount' => (float) $this->allocated_amount,
            'unallocated_amount' => $this->unallocated_amount,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_badge' => $this->status->badge(),
            'paid_on' => $this->paid_on?->toDateString(),
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer?->full_name),
            'customer_code' => $this->whenLoaded('customer', fn () => $this->customer?->customer_code),
            'loan_id' => $this->loan_id,
            'loan_number' => $this->whenLoaded('loan', fn () => $this->loan?->loan_number),
            'employee' => $this->whenLoaded('employee', fn () => $this->employee?->full_name),
            'verifier' => $this->whenLoaded('verifier', fn () => $this->verifier?->full_name),
            'verified_at' => $this->verified_at?->toDateTimeString(),
            'teller_deposit_id' => $this->teller_deposit_id,
            'slip_number' => $this->whenLoaded('tellerDeposit', fn () => $this->tellerDeposit?->slip_number),
            'parent_receipt' => $this->whenLoaded('parent', fn () => $this->parent?->receipt_number),
            'rejection_reason' => $this->rejection_reason,
            'flag_reason' => $this->flag_reason,
            'note' => $this->note,
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($allocation): array => [
                'loan_id' => $allocation->loan_id,
                'loan_number' => $allocation->loan?->loan_number,
                'amount' => (float) $allocation->amount,
                'principal' => (float) ($allocation->loanTransaction?->principal ?? 0),
                'penalty' => (float) ($allocation->loanTransaction?->penalty ?? 0),
                'interest' => (float) ($allocation->loanTransaction?->interest ?? 0),
                'insurance' => (float) ($allocation->loanTransaction?->insurance ?? 0),
                'date' => $allocation->created_at?->toDateString(),
            ])),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
