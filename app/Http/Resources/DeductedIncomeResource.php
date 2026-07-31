<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Loans\Enums\ChargeValueType;
use App\Models\Loan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `DeductedIncomeSchema` in the frontend's types/operations.ts — Loan
 * Fee → Deducted Income.
 *
 * `loanApproved` is the full principal the borrower owes; `incomeAmount` is
 * what was withheld from the payout. The borrower received the difference —
 * which is the whole meaning of "deducted", and why the two columns sit side by
 * side on the legacy screen.
 *
 * @mixin Loan
 */
final class DeductedIncomeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'customerName' => $this->customer->fullName(),
            'branch' => $this->branch_id === null ? '' : $this->branch->name,
            'loanApproved' => $this->principal_amount,
            'incomeAmount' => $this->fee_charged,
            // Disbursement is when the fee was taken. A loan is never charged
            // before it pays out, so this is never null on a row that appears.
            'date' => $this->disbursement_date?->toDateString(),

            'loanId' => (string) $this->id,
            'loanNumber' => $this->loan_number,
            'customerId' => (string) $this->customer_id,
            'branchId' => $this->branch_id === null ? null : (string) $this->branch_id,

            /*
             * The terms the charge came from, so a reader can check the figure
             * rather than take it on trust. `feeType` says how to read
             * `feeRate`: a percentage, or a flat amount in shillings.
             */
            'feeType' => $this->fee_type_snapshot instanceof ChargeValueType ? $this->fee_type_snapshot->value : null,
            'feeRate' => $this->fee_amount_snapshot,
            'insuranceAmount' => $this->insurance_amount_snapshot,
            'netDisbursed' => $this->principal()->subtract($this->feeCharged())->toDecimalString(),
        ];
    }
}
