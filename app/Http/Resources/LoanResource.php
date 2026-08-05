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

            /*
             * Where the loan sits in the approval chain. Null once it has left
             * it — cleared through, rejected, or returned to the officer. A
             * held loan keeps its stage, because that is where it goes back to.
             */
            'approvalStageId' => self::id($this->approval_stage_id),
            'holdResumeStatus' => $this->hold_resume_status?->value,

            'disbursementDate' => $this->disbursement_date?->toDateString(),
            'expectedCompletionDate' => $this->expected_completion_date?->toDateString(),
            'approvedBy' => self::id($this->approved_by),
            'approvedAt' => $this->approved_at?->toIso8601String(),
            'rejectedReason' => $this->rejected_reason,
            'closedAt' => $this->closed_at?->toIso8601String(),
            'frozenUntil' => $this->frozen_until?->toDateString(),

            /*
             * Early settlement, always emitted.
             *
             * Flat on the loan because they describe the loan: a settled loan
             * closed on a date, having forgiven an amount, and both belong
             * beside `closedAt` where every consumer already looks. Null and
             * "0.00" respectively when the loan simply ran its course, which is
             * what distinguishes a settlement from an ordinary closure.
             */
            'earlySettledAt' => $this->early_settled_at?->toIso8601String(),
            'interestWaived' => $this->interest_waived,

            /*
             * The full settlement record, when the caller loaded it.
             *
             * Nested rather than five more flat keys because these five facts
             * are only meaningful together and only exist together — there is
             * no such thing as a settlement reference on a loan that was never
             * settled. A null block says "not settled" once, instead of five
             * nulls saying it five times and inviting a screen to render one
             * without the others.
             */
            'earlySettlement' => $this->earlySettlement(),
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
     * The five things a settlement screen has to show, from the record itself.
     *
     * Settlement date, interest waived, final amount paid, reference and
     * officer — every one of them served, none of them derivable in the
     * browser. `amountPaid` in particular is NOT the outstanding balance the
     * customer had: the waiver removed part of that before the money was taken,
     * so any figure the frontend arrived at by subtracting would be the amount
     * owed before forgiveness, not the amount actually handed over.
     *
     * Null when the loan was not settled early. Absent — a MissingValue — when
     * the caller did not load the relations, which is the same convention the
     * schedule totals use: an omitted key means "not loaded", where a null
     * would claim "not settled", and those are different answers.
     *
     * @return array<string, mixed>|MissingValue|null
     */
    private function earlySettlement(): array|MissingValue|null
    {
        if (! $this->relationLoaded('earlySettledBy') && ! $this->relationLoaded('earlySettlementPayment')) {
            return new MissingValue;
        }

        if ($this->early_settled_at === null) {
            return null;
        }

        $payment = $this->earlySettlementPayment;
        $officer = $this->earlySettledBy;

        return [
            'settledAt' => $this->early_settled_at->toIso8601String(),
            'interestWaived' => $this->interest_waived,
            /*
             * Null, not "0.00", when the waiver alone settled the loan. A loan
             * whose whole remaining balance was unearned interest took no
             * money, and a zero here would read as "the customer paid nothing"
             * rather than "there was nothing left to pay".
             */
            'amountPaid' => $payment?->amount,
            'reference' => $payment?->payment_reference,
            'officerId' => self::id($this->early_settled_by),
            'officerName' => $officer?->name,
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
