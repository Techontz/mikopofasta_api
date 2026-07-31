<?php

declare(strict_types=1);

namespace App\Domain\Repayments\Services;

use App\Domain\Loans\Enums\LoanScheduleStatus;
use App\Domain\Repayments\DTOs\AllocationLine;
use App\Domain\Repayments\DTOs\AllocationResult;
use App\Models\LoanSchedule;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * THE allocation rule — spec §7, and the locked-in Decision 2.
 *
 * "Repayment allocation order is fixed system-wide: Penalty → Interest →
 * Principal." §7 adds the walk: "iterate the loan's oldest unpaid
 * loan_schedules rows in due-date order; within each installment, apply the
 * incoming amount to penalty_due → interest_due → principal_due in that order
 * before moving to the next installment."
 *
 * There is exactly one implementation, as §7 demands, and all three intake
 * channels — provider webhook, teller cash, and suspense resolution — call it.
 * A second copy would be a second answer to "how much of this payment cleared
 * the customer's interest", which is the kind of disagreement that shows up as
 * an unexplained arrears figure months later.
 *
 * Pure: it computes and returns, and writes nothing. The caller persists
 * inside its own transaction alongside the ledger posting.
 */
final class PaymentAllocator
{
    /**
     * Spreads `$amount` across the loan's outstanding installments.
     *
     * @param Collection<int, LoanSchedule> $schedules
     */
    public function allocate(Money $amount, Collection $schedules): AllocationResult
    {
        $remaining = $amount;

        /** @var list<AllocationLine> $lines */
        $lines = [];

        // Oldest installment first — a payment always clears the earliest
        // debt, never the most convenient one.
        $ordered = $schedules->sortBy('installment_number')->values();

        foreach ($ordered as $schedule) {
            if (! $remaining->isPositive()) {
                break;
            }

            if (! $schedule->outstandingTotal()->isPositive()) {
                continue;
            }

            // Penalty → Interest → Principal, within this installment, before
            // moving on. Each portion is capped by what is actually owed.
            $penalty = $remaining->min($schedule->outstandingPenalty());
            $remaining = $remaining->subtract($penalty);

            $interest = $remaining->min($schedule->outstandingInterest());
            $remaining = $remaining->subtract($interest);

            $principal = $remaining->min($schedule->outstandingPrincipal());
            $remaining = $remaining->subtract($principal);

            $touched = $penalty->add($interest)->add($principal);

            if ($touched->isPositive()) {
                $lines[] = new AllocationLine(
                    scheduleId: (int) $schedule->getKey(),
                    penalty: $penalty,
                    interest: $interest,
                    principal: $principal,
                );
            }
        }

        return new AllocationResult(lines: $lines, unallocated: $remaining);
    }

    /**
     * Applies an allocation line to its installment and returns the updated
     * attributes — including the recomputed status.
     *
     * `paid` never exceeds `due` because the allocator caps every portion by
     * what was outstanding, so an overpayment lands in `unallocated` rather
     * than inflating a schedule row.
     *
     * @return array<string, string>
     */
    public function applyToSchedule(LoanSchedule $schedule, AllocationLine $line): array
    {
        $penaltyPaid = Money::of($schedule->penalty_paid)->add($line->penalty);
        $interestPaid = Money::of($schedule->interest_paid)->add($line->interest);
        $principalPaid = Money::of($schedule->principal_paid)->add($line->principal);

        $fullyPaid = ! $penaltyPaid->lessThan($schedule->penaltyDue())
            && ! $interestPaid->lessThan($schedule->interestDue())
            && ! $principalPaid->lessThan($schedule->principalDue());

        return [
            'penalty_paid' => $penaltyPaid->toDecimalString(),
            'interest_paid' => $interestPaid->toDecimalString(),
            'principal_paid' => $principalPaid->toDecimalString(),
            'status' => ($fullyPaid ? LoanScheduleStatus::Paid : LoanScheduleStatus::Partial)->value,
        ];
    }
}
