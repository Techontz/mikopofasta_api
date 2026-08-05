<?php

declare(strict_types=1);

namespace App\Domain\Loans\Engine\Strategies;

use App\Domain\Loans\DTOs\ScheduleInstallment;
use App\Domain\Loans\Engine\InterestStrategy;
use App\Domain\Loans\Engine\LoanTerms;
use App\Support\Money;

/**
 * Reducing balance, constant amortisation — equal principal, declining interest.
 *
 *     Principal per period = Principal ÷ Periods
 *     Interest for period  = Outstanding balance × Rate
 *     Installment          = Principal portion + Interest portion   (declines)
 *
 * Interest is charged on what is still owed, so it falls with every payment.
 * This is one of the two internationally used reducing-balance methods; the
 * other is the annuity/EMI schedule in ReducingBalanceAnnuityStrategy, which
 * holds the INSTALLMENT constant instead of the principal portion.
 *
 * Both are "reducing balance" and both are standard. They differ only in what
 * is held constant, and they produce the same total interest ordering: this one
 * front-loads repayment (higher early installments, less total interest), the
 * annuity smooths it.
 *
 * **This strategy is the one the system has always used for `REDUCING`, and it
 * keeps that code so no existing loan or product changes meaning.**
 *
 * ## Worked example
 *
 *     Principal   100,000 · Rate 10% per period · 4 periods
 *
 *     #  Opening   Principal  Interest  Installment  Closing
 *     1  100,000      25,000    10,000       35,000   75,000
 *     2   75,000      25,000     7,500       32,500   50,000
 *     3   50,000      25,000     5,000       30,000   25,000
 *     4   25,000      25,000     2,500       27,500        0
 *                    -------   -------
 *                    100,000    25,000
 *
 * ## Exactness
 *
 * The final installment clears whatever principal remains rather than taking a
 * pre-computed share, so rounding can never leave a residue on a loan the
 * borrower has fully paid. Interest is always computed from the running
 * balance, so it is exact at every step by construction.
 */
final class ReducingBalanceStrategy implements InterestStrategy
{
    public function code(): string
    {
        return 'REDUCING';
    }

    public function describe(): string
    {
        return 'Equal principal each installment; interest charged on the outstanding balance, so the instalment declines over time.';
    }

    public function schedule(LoanTerms $terms): array
    {
        $count = $terms->installmentCount();

        $outstanding = $terms->principal;
        $principalParts = $terms->principal->allocate($count);

        $installments = [];

        for ($i = 1; $i <= $count; $i++) {
            $isLast = $i === $count;

            // Interest first: it is charged on the balance as it stands BEFORE
            // this installment's principal is repaid.
            $interestDue = $outstanding->percentage($terms->periodicRate());

            $principalDue = $isLast ? $outstanding : $principalParts[$i - 1];
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

    /** Exposed for the verification tests, which walk the balance themselves. */
    public function openingBalance(LoanTerms $terms, int $installmentNumber): Money
    {
        $outstanding = $terms->principal;
        $parts = $terms->principal->allocate($terms->installmentCount());

        for ($i = 1; $i < $installmentNumber; $i++) {
            $outstanding = $outstanding->subtract($parts[$i - 1]);
        }

        return $outstanding;
    }
}
