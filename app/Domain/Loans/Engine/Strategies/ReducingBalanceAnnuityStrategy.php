<?php

declare(strict_types=1);

namespace App\Domain\Loans\Engine\Strategies;

use App\Domain\Loans\DTOs\ScheduleInstallment;
use App\Domain\Loans\Engine\InterestStrategy;
use App\Domain\Loans\Engine\LoanTerms;
use App\Support\Money;

/**
 * Reducing balance, annuity method — the standard amortisation schedule.
 *
 * The textbook banking formula, unmodified:
 *
 *                 r (1 + r)ⁿ
 *     EMI = P × ───────────────
 *                (1 + r)ⁿ − 1
 *
 * where P is the principal, r the rate per period and n the number of periods.
 * Then, for each installment:
 *
 *     Interest  = Outstanding × r
 *     Principal = EMI − Interest
 *     Outstanding decreases by the principal portion
 *
 * The INSTALLMENT is constant; the split moves from mostly interest to mostly
 * principal as the balance falls. This is what most people mean by "an
 * amortisation schedule".
 *
 * ## Worked example
 *
 *     Principal 100,000 · Rate 10% per period · 4 periods
 *     (1.1)⁴ = 1.4641
 *     EMI = 100,000 × (0.1 × 1.4641) ÷ 0.4641 = 31,547.08
 *
 *     #  Opening    Interest  Principal  Installment   Closing
 *     1  100,000   10,000.00  21,547.08    31,547.08  78,452.92
 *     2   78,452.92  7,845.29  23,701.79    31,547.08  54,751.13
 *     3   54,751.13  5,475.11  26,071.97    31,547.08  28,679.16
 *     4   28,679.16  2,867.92  28,679.16    31,547.08          0
 *
 * ## Zero interest
 *
 * The formula divides by (1 + r)ⁿ − 1, which is zero when r is zero. A
 * zero-rate loan is a real product (an interest-free staff advance), so that
 * case short-circuits to equal principal and no interest rather than dividing
 * by zero.
 *
 * ## Exactness
 *
 * The EMI is computed once with BCMath at 20 decimal places and then rounded to
 * minor units — floats are never involved, at any step. Each period's interest
 * is taken from the running integer balance, and the FINAL installment repays
 * whatever principal remains rather than EMI − interest. That last point is
 * what guarantees the schedule closes at exactly zero: rounding drift over n
 * periods lands in the final installment, which is how every real amortisation
 * table handles it.
 */
final class ReducingBalanceAnnuityStrategy implements InterestStrategy
{
    /** Working precision for the EMI. Far beyond what minor units can express. */
    private const int SCALE = 20;

    public function code(): string
    {
        return 'REDUCING_EMI';
    }

    public function describe(): string
    {
        return 'Equal instalment (EMI) amortisation; interest charged on the outstanding balance, with the principal share growing each period.';
    }

    public function schedule(LoanTerms $terms): array
    {
        $count = $terms->installmentCount();

        /*
         * The PERIODIC rate throughout — the EMI formula's `r` is defined per
         * period, so the annuity and the interest it amortises must be computed
         * from the same figure. Reading the raw configured rate here and the
         * periodic rate below would silently produce an EMI that never closes
         * the balance under any basis but the default.
         */
        $rate = $terms->periodicRate();

        if ($rate->isZero()) {
            return $this->interestFree($terms, $count);
        }

        $emi = $this->equalInstallment($terms->principal, $rate->toDecimalString(), $count);

        $outstanding = $terms->principal;
        $installments = [];

        for ($i = 1; $i <= $count; $i++) {
            $isLast = $i === $count;

            $interestDue = $outstanding->percentage($rate);

            /*
             * The final installment repays the remaining balance outright.
             *
             * Taking EMI − interest on the last period would leave a few minor
             * units either side of zero, depending on how the roundings fell —
             * a loan that can never be closed, or one that closes owing the
             * borrower money.
             */
            $principalDue = $isLast
                ? $outstanding
                : $emi->subtract($interestDue)->min($outstanding);

            $outstanding = $outstanding->subtract($principalDue);

            $installments[] = new ScheduleInstallment(
                installmentNumber: $i,
                dueDate: $terms->dueDate($i),
                principalDue: $principalDue,
                interestDue: $interestDue,
            );
        }

        return $installments;
    }

    /**
     * `EMI = P × r(1+r)ⁿ / ((1+r)ⁿ − 1)`, in exact decimal arithmetic.
     *
     * `$ratePercent` arrives as a percentage string ("10.000"); the formula
     * needs it as a fraction, hence the divide by 100.
     */
    public function equalInstallment(Money $principal, string $ratePercent, int $periods): Money
    {
        $r = bcdiv($ratePercent, '100', self::SCALE);
        $onePlusR = bcadd('1', $r, self::SCALE);
        $compound = bcpow($onePlusR, (string) $periods, self::SCALE);

        $numerator = bcmul($r, $compound, self::SCALE);
        $denominator = bcsub($compound, '1', self::SCALE);

        $factor = bcdiv($numerator, $denominator, self::SCALE);
        $emi = bcmul($principal->toDecimalString(), $factor, self::SCALE);

        return Money::of($this->roundToMinor($emi));
    }

    /**
     * A zero-rate loan: equal principal, no interest.
     *
     * @return list<ScheduleInstallment>
     */
    private function interestFree(LoanTerms $terms, int $count): array
    {
        $parts = $terms->principal->allocate($count);
        $installments = [];

        for ($i = 1; $i <= $count; $i++) {
            $installments[] = new ScheduleInstallment(
                installmentNumber: $i,
                dueDate: $terms->dueDate($i),
                principalDue: $parts[$i - 1],
                interestDue: Money::zero(),
            );
        }

        return $installments;
    }

    /**
     * Rounds a high-precision decimal string to two places, half-up.
     *
     * BCMath truncates rather than rounds, so the half is added explicitly
     * before cutting — `bcadd($v, '0.005', 2)` on a positive value is round
     * half-up to the cent.
     */
    private function roundToMinor(string $value): string
    {
        return bcadd($value, '0.005', 2);
    }
}
