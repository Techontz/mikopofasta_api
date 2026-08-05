<?php

declare(strict_types=1);

namespace App\Domain\Loans\Engine;

use App\Support\Percentage;

/**
 * What the number in `loan_products.interest_rate` actually means.
 *
 * P2 — the one business question the client has deliberately left open:
 *
 *     "DO NOT implement any assumption. Leave this configurable. The client
 *      will confirm later whether interest is per repayment period or per annum
 *      (APR). Design the architecture so either option can be enabled later
 *      without changing the loan engine."
 *
 * This interface is that architecture. A basis converts the configured rate
 * into the two spans a formula can ask for, and nothing else in the system is
 * allowed to have an opinion on the question.
 *
 * ## Why two spans and not one
 *
 * The formulas genuinely need different things. FLAT and both reducing
 * formulas charge per installment; SIMPLE charges once across the whole
 * tenure. Collapsing them into a single "the rate" is precisely the ambiguity
 * that made P2 a question in the first place, so the ambiguity is named here
 * instead: a strategy says which span it means, and the basis says what that
 * span is worth.
 *
 * ## The contract
 *
 * MUST be pure — same inputs, same answer, no clock, no database, no config.
 * MUST NOT change the installment count or any due date. A basis prices a
 * loan; it does not reshape it.
 *
 * @see AsConfiguredBasis  today's behaviour, and the seeded default
 * @see PerAnnumBasis      APR, implemented and seeded inactive pending P2
 */
interface RateBasis
{
    /** The `interest_rate_bases.code` this implements. */
    public function code(): string;

    /** One line an administrator reads when choosing between bases. */
    public function describe(): string;

    /**
     * The rate charged on ONE installment period.
     *
     * @param int $frequencyDays days between installments
     * @param int $tenureDays the loan's full term
     */
    public function perPeriod(Percentage $configured, int $frequencyDays, int $tenureDays): Percentage;

    /**
     * The rate charged ONCE across the whole tenure.
     */
    public function perTenure(Percentage $configured, int $frequencyDays, int $tenureDays): Percentage;
}
