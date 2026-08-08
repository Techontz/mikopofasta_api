<?php

declare(strict_types=1);

namespace App\Domain\Hr\Services;

use App\Models\SalaryAdvanceCategory;
use App\Models\StaffAdvance;
use App\Support\Money;

/**
 * What a salary advance costs, and what it takes off each payslip.
 *
 * The single implementation, in the same spirit as PenaltyCalculator and
 * LoanFeeCalculator: the request (which snapshots the terms), payroll (which
 * recovers against them) and every screen that shows a remaining balance all
 * come here rather than each deriving the arithmetic.
 *
 * ## What this replaces
 *
 * `PayrollCalculator::RECOVERY_PER_PERIOD` — a flat 50,000 deducted from
 * everyone with an outstanding advance, regardless of what they borrowed. Its
 * own docblock conceded the figure was picked rather than derived, because §11
 * says an advance is "recovered automatically from payroll" without giving a
 * schedule.
 *
 * The category supplies the schedule the specification omits: an advance is
 * recovered over `recovery_periods` payslips, so the deduction scales with the
 * amount instead of the term scaling with it. Two consequences follow, and both
 * were bugs before:
 *
 *   - Recovery is **capped at what is still owed**. A flat deduction against a
 *     balance smaller than itself over-recovers, and the money has nowhere to
 *     go.
 *   - An advance **finishes**. Nothing previously set `Recovered`, so a
 *     disbursed advance stayed outstanding for ever and payroll deducted
 *     against it every month indefinitely.
 */
final class SalaryAdvanceCalculator
{
    /**
     * Interest in shillings, from the band's rate.
     *
     * Simple interest on the principal, charged once — not per period. The
     * legacy screens print "Interest" as a single figure beside the principal
     * and never show it accruing, so compounding it over the recovery term
     * would be inventing a pricing model the screens contradict.
     */
    public function interestOn(Money $principal, SalaryAdvanceCategory $category): Money
    {
        return $principal->percentage($category->interestRate());
    }

    /**
     * Everything the employee owes: principal + interest + charge fee.
     *
     * The fee is part of what is recovered rather than withheld at
     * disbursement, which is the opposite of how a loan fee works. The legacy
     * Salary Advance screens print the charge fee in its own column beside the
     * remaining balance, and a fee already taken would not belong there.
     */
    public function totalRepayable(StaffAdvance $advance): Money
    {
        return $advance->amountMoney()
            ->add($advance->interestMoney())
            ->add($advance->chargeFeeMoney());
    }

    /** What is still owed. Never negative — an overpayment is not a debt. */
    public function outstanding(StaffAdvance $advance): Money
    {
        $remaining = $this->totalRepayable($advance)->subtract($advance->recoveredMoney());

        return $remaining->isNegative() ? Money::zero() : $remaining;
    }

    /**
     * What this payslip should take.
     *
     * The total spread evenly across the agreed periods, then capped at what
     * remains — so the last instalment collects the rounding rather than
     * leaving a few shillings outstanding for ever, and an advance nearly clear
     * is never over-recovered.
     *
     * Zero when nothing is owed, which is what stops payroll writing a
     * deduction row for a settled advance.
     */
    public function recoveryFor(StaffAdvance $advance): Money
    {
        $outstanding = $this->outstanding($advance);

        if (! $outstanding->isPositive()) {
            return Money::zero();
        }

        $periods = max(1, $advance->recovery_periods);

        /*
         * Divided from the TOTAL, not from what remains: dividing the remainder
         * each time would shrink the instalment asymptotically and the advance
         * would never actually clear.
         *
         * `allocate()` rather than `divide()`, and the first (largest) part.
         * Dividing 100.00 over three periods gives 33.33, and three of those
         * leave a cent outstanding — so the advance would run a fourth period
         * to collect it. Allocating puts the remainder cents in the earliest
         * instalments, so the agreed term is the actual term.
         */
        $perPeriod = $this->totalRepayable($advance)->allocate($periods)[0];

        return $perPeriod->greaterThan($outstanding) ? $outstanding : $perPeriod;
    }

    /**
     * Whether this recovery settles the advance.
     *
     * Asked after the deduction is decided rather than after it is applied, so
     * the caller can close the advance in the same transaction that recovers
     * the last of it.
     */
    public function settles(StaffAdvance $advance, Money $recovery): bool
    {
        return ! $this->outstanding($advance)->greaterThan($recovery);
    }
}
