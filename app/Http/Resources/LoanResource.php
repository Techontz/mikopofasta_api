<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Loan;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;

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

            /*
             * Two ways in, one meaning.
             *
             * `show` eager-loads the schedules and the totals come off the
             * objects. `index` cannot — a page of loans would be a page of
             * schedule loads — so it asks for the same two sums in SQL via
             * `withScheduleTotals()`, and they arrive as aggregate attributes.
             *
             * Whichever the caller used, the field means the same thing and is
             * formatted the same way, so a list row and a detail page can never
             * disagree about what a loan owes.
             *
             * Absent when neither was asked for. That is deliberate: omitting
             * the key says "not loaded", where a zero would say "owes nothing",
             * and those are different claims.
             */
            'totalPayable' => $this->scheduleTotal(
                fn (): Money => $this->totalPayable(),
                fn (): Money => $this->due(),
            ),
            'outstandingTotal' => $this->scheduleTotal(
                fn (): Money => $this->outstandingTotal(),
                fn (): Money => $this->due()->subtract($this->paid()),
            ),
        ];
    }

    /**
     * Resolves one total from whichever source the caller loaded.
     *
     * @param callable(): Money $fromRelation the schedules are in memory
     * @param callable(): Money $fromAggregate only the SQL sums are
     */
    private function scheduleTotal(callable $fromRelation, callable $fromAggregate): mixed
    {
        if ($this->relationLoaded('schedules')) {
            return $fromRelation()->toDecimalString();
        }

        /*
         * `array_key_exists`, not `isset`. A loan approved but not yet
         * scheduled sums to NULL, and `isset` cannot tell that apart from a
         * caller who never asked for the sums at all. The first owes zero and
         * should say so; the second has no answer and must stay absent.
         */
        if (! array_key_exists('schedule_due_total', $this->resource->getAttributes())) {
            return new MissingValue;
        }

        return $fromAggregate()->toDecimalString();
    }

    /**
     * A loan approved but not yet scheduled sums to NULL rather than to a row
     * of zeros, and NULL here means the same thing zero does: nothing is owed
     * before there are installments to owe it against.
     */
    private function due(): Money
    {
        return Money::of((string) ($this->schedule_due_total ?? '0.00'));
    }

    private function paid(): Money
    {
        return Money::of((string) ($this->schedule_paid_total ?? '0.00'));
    }

    private static function id(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
