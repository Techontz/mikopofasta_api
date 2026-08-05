<?php

declare(strict_types=1);

namespace App\Domain\Loans\Engine\Strategies;

use App\Domain\Loans\DTOs\ScheduleInstallment;
use App\Domain\Loans\Engine\InterestStrategy;
use App\Domain\Loans\Engine\LoanTerms;

/**
 * Flat rate — the rate is charged every period, always on the ORIGINAL
 * principal.
 *
 *     Interest per period = Principal × Rate
 *     Total interest      = Principal × Rate × Periods
 *
 * Interest does not shrink as the loan is repaid. That is the defining
 * difference from reducing balance, and it is why a flat loan costs the
 * borrower considerably more than a reducing one at the same headline rate —
 * the borrower keeps paying interest on money they have already returned.
 *
 * ## Worked example — the client's own
 *
 *     Principal    100,000
 *     Rate              10%
 *     Duration           5 months
 *     Interest      50,000     (100,000 × 10% × 5)
 *     Total        150,000
 *     Installment   30,000     (150,000 ÷ 5)
 *
 * Every installment is 20,000 principal + 10,000 interest.
 *
 * ## Rounding
 *
 * The per-period interest is exact — one multiplication, no division — so
 * unlike SIMPLE there is no remainder to spread. Principal still goes through
 * `allocate()` so the portions sum exactly to the principal.
 */
final class FlatRateStrategy implements InterestStrategy
{
    public function code(): string
    {
        return 'FLAT';
    }

    public function describe(): string
    {
        return 'Interest = Principal × Rate, charged once per installment on the original principal and never reduced by repayment.';
    }

    public function schedule(LoanTerms $terms): array
    {
        $count = $terms->installmentCount();

        // On the ORIGINAL principal, every period. This is the whole formula.
        $interestPerPeriod = $terms->principal->percentage($terms->periodicRate());
        $principalParts = $terms->principal->allocate($count);

        $installments = [];

        for ($i = 1; $i <= $count; $i++) {
            $installments[] = new ScheduleInstallment(
                installmentNumber: $i,
                dueDate: $terms->dueDate($i),
                principalDue: $principalParts[$i - 1],
                interestDue: $interestPerPeriod,
            );
        }

        return $installments;
    }
}
