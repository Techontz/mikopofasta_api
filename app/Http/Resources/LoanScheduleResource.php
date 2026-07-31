<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LoanSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `LoanScheduleSchema` in the frontend's types/loan.ts, plus the
 * outstanding figures its `scheduleOutstanding()` helper derives — computed
 * server-side so the browser never does money arithmetic.
 *
 * @mixin LoanSchedule
 */
final class LoanScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'loanId' => (string) $this->loan_id,
            'installmentNumber' => $this->installment_number,
            'dueDate' => $this->due_date->toDateString(),

            'principalDue' => $this->principal_due,
            'interestDue' => $this->interest_due,
            'penaltyDue' => $this->penalty_due,
            'principalPaid' => $this->principal_paid,
            'interestPaid' => $this->interest_paid,
            'penaltyPaid' => $this->penalty_paid,

            'status' => $this->status->value,

            'totalDue' => $this->totalDue()->toDecimalString(),
            'totalPaid' => $this->totalPaid()->toDecimalString(),
            'outstandingTotal' => $this->outstandingTotal()->toDecimalString(),
        ];
    }
}
