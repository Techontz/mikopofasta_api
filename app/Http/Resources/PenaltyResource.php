<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LoanSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `PenaltySchema` in the frontend's types/operations.ts — one accrued
 * penalty, as Penalty → Penalty List shows it.
 *
 * The row is a `loan_schedules` row, not a record of its own: a penalty is a
 * figure on an installment, and this projects that installment into the five
 * columns the screen draws.
 *
 * `date` is the installment's **due date**, not today. It is the date the
 * penalty relates to, which is what makes the list sortable into the order the
 * legacy screen shows and what a date filter is filtering on.
 *
 * @mixin LoanSchedule
 */
final class PenaltyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $loan = $this->loan;

        return [
            'id' => (string) $this->id,
            'customerName' => $loan->customer->fullName(),
            'branch' => $loan->branch_id === null ? '' : $loan->branch->name,
            'loanAmount' => $loan->principal_amount,
            'penaltyAmount' => $this->penalty_due,
            'date' => $this->due_date->toDateString(),

            /*
             * Beyond the frontend schema, and load-bearing rather than
             * decorative: `outstanding` is what is still owed on the penalty,
             * which is the figure a collector needs. The schema shows the
             * charge; a penalty part-paid still shows its full charge there,
             * and without this there would be no way to tell one from an
             * untouched one.
             */
            'penaltyPaid' => $this->penalty_paid,
            'outstanding' => $this->outstandingPenalty()->toDecimalString(),

            'loanId' => (string) $loan->id,
            'loanNumber' => $loan->loan_number,
            'customerId' => (string) $loan->customer_id,
            'branchId' => $loan->branch_id === null ? null : (string) $loan->branch_id,
            'installmentNumber' => $this->installment_number,
            'status' => $this->status->value,
        ];
    }
}
