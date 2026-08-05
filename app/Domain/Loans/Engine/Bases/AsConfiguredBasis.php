<?php

declare(strict_types=1);

namespace App\Domain\Loans\Engine\Bases;

use App\Domain\Loans\Engine\RateBasis;
use App\Support\Percentage;

/**
 * The rate is taken exactly as configured, and each formula decides what span
 * it applies to.
 *
 * This is what the system has always done, restated as a policy object rather
 * than left as an unexamined assumption. Under it:
 *
 *   SIMPLE   charges the rate once over the whole tenure
 *   FLAT     charges the rate every installment, on the original principal
 *   REDUCING charges the rate every installment, on what is still outstanding
 *
 * ## Why the default is this and not APR
 *
 * P2 is open, and continuity is not an assumption. Every loan in the book was
 * priced this way, every product an administrator has configured was entered
 * meaning this, and the client's own worked examples in the engine brief —
 * 100,000 at 20% producing 20,000 of interest, 100,000 at 10% over five months
 * producing 50,000 — only come out right under it. Making APR the default
 * would silently reprice the entire portfolio to answer a question nobody has
 * answered yet.
 *
 * So this returns the configured rate untouched for both spans. The conversion
 * is a no-op, which is the strongest possible guarantee that introducing the
 * mechanism changed no arithmetic.
 */
final class AsConfiguredBasis implements RateBasis
{
    public const string CODE = 'AS_CONFIGURED';

    public function code(): string
    {
        return self::CODE;
    }

    public function describe(): string
    {
        return 'The configured rate is used as-is, applied per installment or across the tenure as the formula defines.';
    }

    public function perPeriod(Percentage $configured, int $frequencyDays, int $tenureDays): Percentage
    {
        return $configured;
    }

    public function perTenure(Percentage $configured, int $frequencyDays, int $tenureDays): Percentage
    {
        return $configured;
    }
}
