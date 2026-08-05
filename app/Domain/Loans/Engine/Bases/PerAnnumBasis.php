<?php

declare(strict_types=1);

namespace App\Domain\Loans\Engine\Bases;

use App\Domain\Loans\Engine\RateBasis;
use App\Support\Percentage;

/**
 * The configured rate is an ANNUAL rate, and the engine pro-rates it to the
 * loan's cadence.
 *
 *     per period = annual × frequency_days / 365
 *     per tenure = annual × tenure_days    / 365
 *
 * So 24% per annum on a monthly product charges 24 × 30/365 = 1.973% an
 * installment, and the same 24% on a 90-day simple-interest loan charges
 * 24 × 90/365 = 5.918% once.
 *
 * ## Simple interest, not compounded
 *
 * The periodic rate is the annual rate divided pro rata, not the rate that
 * compounds to it — `annual / n`, not `(1 + annual)^(1/n) − 1`. This is the
 * convention Tanzanian microfinance and the BoT's own disclosure guidance use,
 * and it is what a borrower reading "24% per year, 2% a month" expects. The
 * compounding variant is a different basis and would be a different class,
 * which is the point of there being an interface.
 *
 * ## 365, including in a leap year
 *
 * A fixed denominator, so the same product prices the same loan identically in
 * 2027 and in 2028. Actual/365 is the standard fixed-basis convention; letting
 * the divisor follow the calendar would make a February loan cost a different
 * amount from a March one for no reason a customer could be told.
 *
 * ## Status: implemented, seeded, and switched off
 *
 * P2 is unanswered. This class exists so that answering it is a data change,
 * and its `interest_rate_bases` row is seeded with `is_active = false` so that
 * nobody can select it before the client has actually decided. Nothing in the
 * live book is priced on it.
 */
final class PerAnnumBasis implements RateBasis
{
    public const string CODE = 'PER_ANNUM';

    /** Actual/365 — fixed, so a leap year does not reprice a product. */
    private const int DAYS_IN_YEAR = 365;

    public function code(): string
    {
        return self::CODE;
    }

    public function describe(): string
    {
        return 'The configured rate is annual (APR); the engine pro-rates it to the loan\'s repayment cadence on an actual/365 basis.';
    }

    public function perPeriod(Percentage $configured, int $frequencyDays, int $tenureDays): Percentage
    {
        return $configured->scaledBy($frequencyDays, self::DAYS_IN_YEAR);
    }

    public function perTenure(Percentage $configured, int $frequencyDays, int $tenureDays): Percentage
    {
        return $configured->scaledBy($tenureDays, self::DAYS_IN_YEAR);
    }
}
