<?php

declare(strict_types=1);

namespace App\Domain\Loans\DTOs;

use App\Domain\Loans\Enums\InterestFormulaCode;
use App\Support\Money;
use App\Support\Percentage;
use Carbon\CarbonImmutable;

/**
 * Everything LoanScheduleGenerator needs, and nothing it does not.
 *
 * `startDate` is an explicit input rather than being read from the clock
 * inside the generator: that is what makes schedule generation deterministic
 * and therefore verifiable — the same request always produces the same plan.
 */
final readonly class ScheduleRequest
{
    public function __construct(
        public Money $principal,
        public Percentage $interestRate,
        public int $tenureDays,
        public int $frequencyDays,
        public InterestFormulaCode $formula,
        public CarbonImmutable $startDate,
    ) {}
}
