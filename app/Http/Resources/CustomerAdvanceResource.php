<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\CustomerAdvances\Services\CustomerAdvanceCalculator;
use App\Models\CustomerAdvance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the customer Salary Advance register.
 *
 * `status` is the screens' vocabulary — `active` and `paid` where the enum says
 * `disbursed` and `settled` — mapped here rather than renaming the enum, which
 * describes what happened to the money and is right to.
 *
 * `principalRepaid` and `profitRepaid` are the client's two buckets made
 * visible on the row: what has gone back to operational principal, and what has
 * been earned. They are stored rather than derived on read, so this row and the
 * ledger cannot drift.
 *
 * @mixin CustomerAdvance
 */
final class CustomerAdvanceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $calculator = app(CustomerAdvanceCalculator::class);

        return [
            'id' => (string) $this->id,
            'reference' => $this->reference,

            'customerId' => (string) $this->customer_id,
            'customerName' => $this->whenLoaded('customer', fn (): string => $this->customer->fullName(), ''),
            'phone' => $this->whenLoaded('customer', fn (): string => (string) $this->customer->phone, ''),
            'branchId' => $this->branch_id === null ? null : (string) $this->branch_id,
            'branch' => $this->whenLoaded(
                'branch',
                fn (): string => $this->branch_id === null ? '' : $this->branch->name,
                '',
            ),

            'categoryId' => $this->salary_advance_category_id === null
                ? null
                : (string) $this->salary_advance_category_id,
            'categoryName' => $this->whenLoaded(
                'category',
                fn (): string => $this->salary_advance_category_id === null ? '' : $this->category->name,
                '',
            ),

            'amount' => $this->amount,
            // Money, not a rate — the Salary Advance screens print a figure.
            'interest' => $this->interest_amount,
            'chargeFee' => $this->charge_fee,
            'recoveryPeriods' => $this->recovery_periods,

            'paidAmount' => $this->amount_repaid,
            'principalRepaid' => $this->principal_repaid,
            'profitRepaid' => $this->profitRepaidMoney()->toDecimalString(),

            'totalRepayable' => $calculator->totalRepayable($this->resource)->toDecimalString(),
            'remaining' => $calculator->outstanding($this->resource)->toDecimalString(),
            'expectedInstalment' => $calculator->expectedInstalment($this->resource)->toDecimalString(),

            'status' => $this->status->forFrontend(),
            'date' => $this->requested_at->toDateString(),
            'overdueDays' => $this->overdueDays(),

            'dueDate' => $this->due_date?->toDateString(),
            'approvedAt' => $this->approved_at?->toIso8601String(),
            'disbursedAt' => $this->disbursed_at?->toIso8601String(),
            'settledAt' => $this->settled_at?->toIso8601String(),
            'rejectionReason' => $this->rejection_reason,
            'fundingAccountId' => $this->funding_account_id === null ? null : (string) $this->funding_account_id,
            'journalEntryId' => $this->journal_entry_id === null ? null : (string) $this->journal_entry_id,
        ];
    }
}
