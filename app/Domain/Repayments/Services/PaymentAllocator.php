<?php

declare(strict_types=1);

namespace App\Domain\Repayments\Services;

use App\Domain\Loans\Enums\LoanScheduleStatus;
use App\Domain\Repayments\DTOs\AllocationLine;
use App\Domain\Repayments\DTOs\AllocationResult;
use App\Models\LoanSchedule;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * THE allocation rule. Client-confirmed, permanent, and implemented once.
 *
 *     1. Penalty
 *     2. Advance
 *     3. Principal
 *     4. Interest
 *
 * This supersedes the earlier Penalty → Interest → Principal order. The two
 * source documents disagreed with each other (`REPAYMENT OVERVIEW` said
 * Penalty → Principal → Interest, `ACCOUNT OVERVIEW` said Penalty → Interest →
 * Principal), the question was carried as **P1 — Pending Client Confirmation**,
 * and the client has now settled it. P1 is closed.
 *
 * There is exactly one implementation, and all intake channels use it — the
 * provider webhook, teller cash, suspense resolution and early settlement. A
 * second copy would be a second answer to "how much of this payment cleared the
 * customer's interest", which surfaces months later as an arrears figure nobody
 * can explain.
 *
 * ## What "Advance" means at step 2 — client-confirmed
 *
 * Advance is not a debt component — an installment has principal, interest and
 * penalty, and nothing else. So step 2 is read as: **a credit the borrower has
 * already paid is spent before any new cash is.**
 *
 * The client has now settled how that credit forms, and it is not what this
 * allocator used to do:
 *
 *     Customer pays early
 *       → the money is HELD as a Customer Advance
 *       → the repayment schedule is left completely unchanged
 *       → when an installment reaches its due date, the advance is consumed
 *       → only the shortfall, if any, is asked of the customer
 *
 * So money paid early no longer reaches a future installment. `$asOf` is what
 * enforces that: an installment is settled only once its due date has arrived,
 * and everything the due installments cannot absorb falls out as `unallocated`
 * for the caller to bank as an advance credit.
 *
 * That is a real behavioural change and it is deliberate. Previously the
 * allocator walked EVERY unpaid installment, so early money silently paid down
 * the tail of the schedule and an advance balance could only form once the
 * whole loan was covered. The borrower's plan changed underneath them, and the
 * advance ledger was almost never exercised. Both are now fixed by the same
 * one-line gate.
 *
 * ## Where the other half lives
 *
 * This class only ever settles what is due *at the moment money arrives*. An
 * installment that falls due later, with an advance already sitting on the
 * loan, is settled by ApplyDueAdvancesAction — the daily pass that consumes
 * held credit the instant it becomes owed, before the overdue job could
 * penalise a borrower for money they have already paid.
 *
 * ## Why principal before interest matters
 *
 * It favours the borrower. Every shilling that clears principal stops earning
 * interest on a reducing-balance loan and shrinks the base a percentage penalty
 * is charged on. The lender is paid last, which is a deliberate and generous
 * ordering — and it is what the client confirmed.
 *
 * Pure: it computes and returns, writing nothing. The caller persists inside its
 * own transaction alongside the ledger posting.
 */
final class PaymentAllocator
{
    /**
     * Spreads `$amount` across the loan's installments that have fallen due.
     *
     * @param Collection<int, LoanSchedule> $schedules
     * @param Money|null $advanceCredit an existing advance balance to consume first
     * @param CarbonImmutable|null $asOf the date the money is being applied on;
     *                                   installments due after it are untouched.
     *                                   Defaults to now, which is what every
     *                                   live intake channel means.
     */
    public function allocate(
        Money $amount,
        Collection $schedules,
        ?Money $advanceCredit = null,
        ?CarbonImmutable $asOf = null,
    ): AllocationResult {
        $cash = $amount;
        $advance = $advanceCredit ?? Money::zero();
        $advanceOpening = $advance;
        $today = ($asOf ?? Date::now()->toImmutable())->startOfDay();

        /** @var list<AllocationLine> $lines */
        $lines = [];

        // Oldest installment first — a payment always clears the earliest debt,
        // never the most convenient one.
        $ordered = $schedules->sortBy('installment_number')->values();

        foreach ($ordered as $schedule) {
            if (! $cash->isPositive() && ! $advance->isPositive()) {
                break;
            }

            if (! $schedule->outstandingTotal()->isPositive()) {
                continue;
            }

            /*
             * The client's rule, in one line: an installment is settled only
             * once it has reached its due date.
             *
             * `break`, not `continue` — the schedule is in date order, so the
             * first installment that has not fallen due means none after it
             * has either. Continuing would let a later installment be settled
             * ahead of an earlier one, which is the thing the ordering above
             * exists to prevent.
             */
            if ($schedule->due_date->startOfDay()->greaterThan($today)) {
                break;
            }

            /*
             * Step 1 — PENALTY, from cash.
             *
             * Penalties come first and are paid with new money. Spending an
             * advance credit on a penalty would mean money the borrower paid
             * early was consumed by a charge for being late, which is the
             * opposite of what an advance is for.
             */
            $penalty = $cash->min($schedule->outstandingPenalty());
            $cash = $cash->subtract($penalty);

            /*
             * Step 2 — ADVANCE.
             *
             * Whatever the borrower has already paid ahead is spent on this
             * installment's principal and interest before any new cash. The
             * split follows the same principal-then-interest order as the cash
             * below it, so the source of the money never changes the answer.
             */
            $advanceToPrincipal = $advance->min($schedule->outstandingPrincipal());
            $advance = $advance->subtract($advanceToPrincipal);

            $advanceToInterest = $advance->min(
                $schedule->outstandingInterest(),
            );
            $advance = $advance->subtract($advanceToInterest);

            // Step 3 — PRINCIPAL, from cash, on whatever the advance left.
            $principalFromCash = $cash->min(
                $schedule->outstandingPrincipal()->subtract($advanceToPrincipal),
            );
            $cash = $cash->subtract($principalFromCash);

            // Step 4 — INTEREST, from cash, last.
            $interestFromCash = $cash->min(
                $schedule->outstandingInterest()->subtract($advanceToInterest),
            );
            $cash = $cash->subtract($interestFromCash);

            $principal = $advanceToPrincipal->add($principalFromCash);
            $interest = $advanceToInterest->add($interestFromCash);

            if ($penalty->add($principal)->add($interest)->isPositive()) {
                $lines[] = new AllocationLine(
                    scheduleId: (int) $schedule->getKey(),
                    penalty: $penalty,
                    interest: $interest,
                    principal: $principal,
                );
            }
        }

        return new AllocationResult(
            lines: $lines,
            /*
             * Cash no DUE installment could absorb — which is now the ordinary
             * outcome of paying early, not just of overpaying.
             *
             * §7: it "is not silently kept in a schedule row". The caller banks
             * it as an advance credit, and it is consumed installment by
             * installment as each one falls due.
             */
            unallocated: $cash,
            advanceConsumed: $advanceOpening->subtract($advance),
        );
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
