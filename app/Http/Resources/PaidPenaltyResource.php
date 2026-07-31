<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PaymentAllocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `PaidPenaltySchema` in the frontend's types/operations.ts — Penalty →
 * Paid Penalty.
 *
 * One row per allocation that touched a penalty. `date` is when the payment was
 * received, which is the point of this screen: the accrued list says what is
 * owed, this one says when it came in.
 *
 * @mixin PaymentAllocation
 */
final class PaidPenaltyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $loan = $this->schedule->loan;

        return [
            'id' => (string) $this->id,
            'customerName' => $loan->customer->fullName(),
            'branch' => $loan->branch_id === null ? '' : $loan->branch->name,
            'paidAmount' => $this->penalty_allocated,
            'date' => $this->payment->received_at->toDateString(),

            'loanId' => (string) $loan->id,
            'loanNumber' => $loan->loan_number,
            'customerId' => (string) $loan->customer_id,
            'branchId' => $loan->branch_id === null ? null : (string) $loan->branch_id,
            'paymentId' => (string) $this->payment_id,
            'paymentReference' => $this->payment->payment_reference,
            'installmentNumber' => $this->schedule->installment_number,
        ];
    }
}
