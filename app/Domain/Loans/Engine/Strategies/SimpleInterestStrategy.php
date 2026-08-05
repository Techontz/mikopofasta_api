<?php

declare(strict_types=1);

namespace App\Domain\Loans\Engine\Strategies;

use App\Domain\Loans\DTOs\ScheduleInstallment;
use App\Domain\Loans\Engine\InterestStrategy;
use App\Domain\Loans\Engine\LoanTerms;

/**
 * Simple interest — one charge over the life of the loan.
 *
 *     Interest = Principal × Rate
 *
 * Charged once, not per period, and it does not change as the loan is repaid.
 * The total is spread evenly across the installments alongside equal principal.
 *
 * ## Worked example — the client's own
 *
 *     Principal    100,000
 *     Rate              20%
 *     Interest      20,000     (100,000 × 20%)
 *     Total        120,000
 *     Installment  120,000 ÷ duration
 *
 * Over 4 installments that is 30,000 each: 25,000 principal + 5,000 interest.
 *
 * ## Rounding
 *
 * Both principal and interest are distributed with `Money::allocate()`, which
 * spreads the remainder one minor unit at a time across the earliest
 * installments. 100,000 of interest over 3 installments is 33,333.34 +
 * 33,333.33 + 33,333.33 — summing to exactly 100,000, never 99,999.99.
 */
final class SimpleInterestStrategy implements InterestStrategy
{
    public function code(): string
    {
        return 'SIMPLE';
    }

    public function describe(): string
    {
        return 'Interest = Principal × Rate, charged once over the whole tenure and spread evenly across the installments.';
    }

    public function schedule(LoanTerms $terms): array
    {
        $count = $terms->installmentCount();

        $principalParts = $terms->principal->allocate($count);
        $interestParts = $terms->principal->percentage($terms->tenureRate())->allocate($count);

        $installments = [];

        for ($i = 1; $i <= $count; $i++) {
            $installments[] = new ScheduleInstallment(
                installmentNumber: $i,
                dueDate: $terms->dueDate($i),
                principalDue: $principalParts[$i - 1],
                interestDue: $interestParts[$i - 1],
            );
        }

        return $installments;
    }
}
