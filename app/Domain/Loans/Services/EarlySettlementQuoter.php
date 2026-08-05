<?php

declare(strict_types=1);

namespace App\Domain\Loans\Services;

use App\Domain\Loans\DTOs\EarlySettlementQuote;
use App\Domain\Loans\Enums\LoanScheduleStatus;
use App\Models\Loan;
use App\Models\LoanAdvance;
use App\Models\LoanSchedule;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * What it costs to close a loan today — client Decision 1, Option B.
 *
 * ## The rule, stated once
 *
 *     payable = all outstanding penalty
 *             + all outstanding principal
 *             + outstanding interest on installments that have ALREADY fallen due
 *
 *     waived  = outstanding interest on installments not yet due
 *
 * This is the **rebate of unearned interest**: the borrower repays what they
 * borrowed, pays for the time they actually had the money, and is forgiven the
 * interest on time they are handing back. It is the standard early-settlement
 * model and the one a customer can be told in a sentence.
 *
 * Penalties are not rebated. A penalty is a charge for something that already
 * happened; settling early does not un-happen it.
 *
 * ## The one policy choice, and it is flagged rather than buried
 *
 * Interest is earned **per installment**, not pro-rated across the current
 * period. A borrower settling on day 20 of a 30-day period pays no interest for
 * those 20 days.
 *
 * That is deliberate and it favours the borrower. The alternative — pro-rating
 * the part-elapsed period — is equally defensible and is what some lenders do.
 * Choosing it would mean charging interest for a period the schedule has not
 * yet billed, which is harder to explain across a counter and would make the
 * quote change every day rather than at each due date. The business should
 * confirm which it wants; changing it is confined to this class.
 *
 * ## Why this is a service and not a method on the action
 *
 * Because it is asked TWICE: once by the officer previewing the figure for a
 * customer, and once at the moment of settlement. A quote computed differently
 * on those two paths is a number quoted across a counter that the system then
 * refuses to honour.
 */
final class EarlySettlementQuoter
{
    /**
     * @param CarbonImmutable|null $asOf explicit so a quote can be reproduced
     */
    public function quote(Loan $loan, ?CarbonImmutable $asOf = null): EarlySettlementQuote
    {
        $today = ($asOf ?? Date::now()->toImmutable())->startOfDay();

        $loan->loadMissing('schedules');

        $penalty = Money::zero();
        $principal = Money::zero();
        $earned = Money::zero();
        $waived = Money::zero();
        $cancelled = 0;

        foreach ($loan->schedules as $schedule) {
            if ($schedule->status === LoanScheduleStatus::Cancelled) {
                continue;
            }

            $penalty = $penalty->add($schedule->outstandingPenalty());
            $principal = $principal->add($schedule->outstandingPrincipal());

            if ($this->hasFallenDue($schedule, $today)) {
                $earned = $earned->add($schedule->outstandingInterest());

                continue;
            }

            /*
             * Not yet due. Its interest is unearned — the borrower is handing
             * the money back before the period it would have paid for.
             */
            $waived = $waived->add($schedule->outstandingInterest());

            if ($schedule->outstandingTotal()->isPositive()) {
                $cancelled++;
            }
        }

        return new EarlySettlementQuote(
            penalty: $penalty,
            principal: $principal,
            interestEarned: $earned,
            interestWaived: $waived,
            advanceHeld: LoanAdvance::balanceFor((int) $loan->getKey()),
            installmentsCancelled: $cancelled,
        );
    }

    /** Whether this installment's period has been billed. */
    public function hasFallenDue(LoanSchedule $schedule, CarbonImmutable $asOf): bool
    {
        return ! $schedule->due_date->startOfDay()->greaterThan($asOf);
    }
}
