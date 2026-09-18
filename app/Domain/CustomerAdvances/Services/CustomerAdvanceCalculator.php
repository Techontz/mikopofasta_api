<?php

declare(strict_types=1);

namespace App\Domain\CustomerAdvances\Services;

use App\Domain\CustomerAdvances\DTOs\AdvanceSplit;
use App\Models\CustomerAdvance;
use App\Models\SalaryAdvanceCategory;
use App\Support\Money;

/**
 * What a customer salary advance costs, and what each payment is made of.
 *
 * The pricing half mirrors SalaryAdvanceCalculator exactly — the same bands
 * price both sides, so charging customers by a second set of arithmetic would
 * be two answers to one question. The second half is what is new here: a
 * customer pays over the counter in amounts of their choosing, so a payment has
 * to be split rather than simply deducted.
 *
 * ## How a payment is split
 *
 * Pro rata across the three things the advance is made of, which is the client's
 * own worked example: an advance of 1,000,000 costing 100,000 in interest is
 * repaid 220,000 a month, and they give the split as 200,000 capital and 20,000
 * profit — 1,000,000 / 1,100,000 of the payment, and 100,000 / 1,100,000 of it.
 *
 * The split is computed from the CUMULATIVE total paid and then reduced by what
 * has already been credited, never from the payment on its own. Splitting each
 * payment independently rounds three times and leaves 1250 a cent or two from
 * zero on a settled advance, with nothing to say which payment did it. Taken
 * cumulatively, the final payment's share is whatever is left, so the
 * receivable closes exactly.
 *
 * The fee share is the residual rather than a third proportion, so the three
 * parts always add back to the payment — two roundings plus a subtraction
 * cannot disagree with their own total, whereas three roundings can.
 */
final class CustomerAdvanceCalculator
{
    /**
     * Interest in shillings, from the band's rate.
     *
     * Simple interest on the principal, charged once, not per period — the
     * legacy Salary Advance screens print a single Interest figure beside the
     * principal and never show it accruing.
     */
    public function interestOn(Money $principal, SalaryAdvanceCategory $category): Money
    {
        return $principal->percentage($category->interestRate());
    }

    /** Everything the customer owes: principal + interest + charge fee. */
    public function totalRepayable(CustomerAdvance $advance): Money
    {
        return $advance->amountMoney()
            ->add($advance->interestMoney())
            ->add($advance->chargeFeeMoney());
    }

    /** What is still owed. Never negative — an overpayment is not a debt. */
    public function outstanding(CustomerAdvance $advance): Money
    {
        $remaining = $this->totalRepayable($advance)->subtract($advance->repaidMoney());

        return $remaining->isNegative() ? Money::zero() : $remaining;
    }

    /**
     * The instalment the schedule expects, for the screens that show one.
     *
     * The total spread across the agreed periods and capped at what remains.
     * `allocate()` rather than `divide()`: dividing 100.00 over three periods
     * gives 33.33, and three of those leave a cent outstanding, so the advance
     * would run a fourth period to collect it.
     *
     * A customer is not held to this — they may pay any amount — but it is what
     * the advance was agreed on and what an arrears figure is measured against.
     */
    public function expectedInstalment(CustomerAdvance $advance): Money
    {
        $outstanding = $this->outstanding($advance);

        if (! $outstanding->isPositive()) {
            return Money::zero();
        }

        $perPeriod = $this->totalRepayable($advance)->allocate(max(1, $advance->recovery_periods))[0];

        return $perPeriod->greaterThan($outstanding) ? $outstanding : $perPeriod;
    }

    /**
     * How a payment divides between capital returned and profit earned.
     *
     * @param Money $payment what is being collected now, already checked
     *                       against `outstanding()` by the caller
     */
    public function split(CustomerAdvance $advance, Money $payment): AdvanceSplit
    {
        if (! $payment->isPositive()) {
            return AdvanceSplit::zero();
        }

        $total = $this->totalRepayable($advance);

        /*
         * A zero-value advance cannot be paid against, and taking a proportion
         * of it would divide by zero. Guarded rather than risked: the band
         * could in principle price a zero advance, and the failure would be an
         * exception from Money rather than a business message.
         */
        if (! $total->isPositive()) {
            return AdvanceSplit::zero();
        }

        $paidAfter = $advance->repaidMoney()->add($payment);

        $principalTarget = $advance->amountMoney()->proportion($paidAfter, $total);
        $interestTarget = $advance->interestMoney()->proportion($paidAfter, $total);
        // Residual, so the three targets add back to what has been paid.
        $feeTarget = $paidAfter->subtract($principalTarget)->subtract($interestTarget);

        $principal = $principalTarget->subtract($advance->principalRepaidMoney());
        $interest = $interestTarget->subtract($advance->interestRepaidMoney());

        /*
         * Clamped, then the fee taken as what is left.
         *
         * On a payment small enough that two roundings can exceed it — a single
         * cent against a million-shilling advance — the residual fee comes out
         * NEGATIVE. The posting builder skips non-positive lines, so that would
         * post a debit of the payment against credits of a cent more, and
         * LedgerService would refuse the whole entry. The customer's money would
         * bounce for a rounding artefact.
         *
         * Clamping costs nothing where it does not bite: at settlement the
         * targets land exactly on the agreed figures, so the deltas are already
         * the whole of what remains and none of these bounds is reached.
         */
        $principal = $principal->max(Money::zero())->min($payment);
        $interest = $interest->max(Money::zero())->min($payment->subtract($principal));

        return new AdvanceSplit(
            principal: $principal,
            interest: $interest,
            fee: $payment->subtract($principal)->subtract($interest),
        );
    }

    /** Whether this payment clears the advance. */
    public function settles(CustomerAdvance $advance, Money $payment): bool
    {
        return ! $this->outstanding($advance)->greaterThan($payment);
    }
}
