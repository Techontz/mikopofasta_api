<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Loan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `LoanSchema` in the frontend's types/loan.ts.
 *
 * Money and rates go out as DECIMAL STRINGS, not JSON numbers. The frontend
 * types them `z.number()`, and JSON has only one numeric type — a double — so
 * emitting a raw number would hand the browser a float and undo the exact
 * arithmetic this module is built on. A string is losslessly parseable and
 * still satisfies Zod's coercion at the boundary.
 *
 * @mixin Loan
 */
final class LoanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'loanNumber' => $this->loan_number,
            'customerId' => (string) $this->customer_id,
            'loanProductId' => (string) $this->loan_product_id,
            'repaymentScheduleId' => (string) $this->repayment_schedule_id,
            'groupId' => self::id($this->group_id),
            'branchId' => (string) $this->branch_id,
            'officerId' => (string) $this->officer_id,

            'principalAmount' => $this->principal_amount,
            'interestRateSnapshot' => $this->interest_rate_snapshot,
            'penaltyRateSnapshot' => $this->penalty_rate_snapshot,
            'tenureDays' => $this->tenure_days,
            'requiresMandateSnapshot' => $this->requires_mandate_snapshot,

            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),

            'disbursementDate' => $this->disbursement_date?->toDateString(),
            'expectedCompletionDate' => $this->expected_completion_date?->toDateString(),
            'approvedBy' => self::id($this->approved_by),
            'approvedAt' => $this->approved_at?->toIso8601String(),
            'rejectedReason' => $this->rejected_reason,
            'closedAt' => $this->closed_at?->toIso8601String(),
            'frozenUntil' => $this->frozen_until?->toDateString(),
            'createdBy' => self::id($this->created_by),
            'createdAt' => $this->created_at?->toIso8601String(),
            'deletedAt' => $this->deleted_at?->toIso8601String(),

            // Display and derived values, only when the caller loaded them.
            'customerName' => $this->whenLoaded('customer', fn (): ?string => $this->customer?->fullName()),
            'customerNumber' => $this->whenLoaded('customer', fn (): ?string => $this->customer?->customer_number),
            'branchName' => $this->whenLoaded('branch', fn (): ?string => $this->branch?->name),
            'productName' => $this->whenLoaded('product', fn (): ?string => $this->product?->name),

            'schedules' => LoanScheduleResource::collection($this->whenLoaded('schedules')),
            'totalPayable' => $this->whenLoaded('schedules', fn (): string => $this->totalPayable()->toDecimalString()),
            'outstandingTotal' => $this->whenLoaded('schedules', fn (): string => $this->outstandingTotal()->toDecimalString()),
        ];
    }

    private static function id(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
