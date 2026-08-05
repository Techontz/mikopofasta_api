<?php

declare(strict_types=1);

namespace App\Domain\Loans\Engine;

use App\Domain\Loans\Engine\Bases\AsConfiguredBasis;
use App\Support\Money;
use App\Support\Percentage;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Everything a loan needs to be priced — read from the product, never invented.
 *
 * This is the boundary the whole engine turns on. A strategy receives ONLY this
 * object, so it cannot reach for a model, a config file, a constant or a
 * request. If a number is not here, it is not part of the calculation; and the
 * only way a number gets here is from the loan product the administrator
 * configured.
 *
 * That is what "the Loan Product is the single source of truth" means in code
 * rather than in a comment: the loan itself contributes the principal and the
 * start date, and every other input — rate, cadence, tenure, grace — comes from
 * the product.
 *
 * ## Why a value object and not the Eloquent model
 *
 * Passing `LoanProduct` into a strategy would let a strategy lazy-load a
 * relation, read a column nobody meant it to read, or behave differently
 * depending on what the caller happened to eager-load. A frozen value object
 * makes a schedule a pure function of its inputs, which is what lets the tests
 * verify the arithmetic against figures computed by hand.
 */
final readonly class LoanTerms
{
    private function __construct(
        /** What the borrower receives. */
        public Money $principal,
        /**
         * The rate exactly as the product carries it, before any
         * interpretation.
         *
         * Strategies do NOT read this. They ask for `periodicRate()` or
         * `tenureRate()`, and the `$rateBasis` below decides what this figure
         * is worth over that span. Keeping the raw value here means the number
         * an administrator typed is always recoverable, whatever basis the
         * product is on.
         */
        public Percentage $interestRate,
        public int $tenureDays,
        /** Days between installments, from the product's repayment schedule. */
        public int $frequencyDays,
        /**
         * Days before the first installment falls due, beyond the first period.
         *
         * Zero means the first installment is due one period after
         * disbursement, which is the ordinary case. A grace period pushes every
         * due date out; it does NOT change what is owed — a product that wanted
         * to forgive interest during grace would be a different formula, and
         * inventing that here would be inventing mathematics.
         */
        public int $gracePeriodDays,
        public CarbonImmutable $startDate,
        /**
         * What the configured rate MEANS — P2, left open by the client and
         * therefore made switchable rather than assumed.
         *
         * Defaults to AsConfiguredBasis, which returns the rate untouched, so
         * the mechanism exists without changing a single figure the engine
         * produces today.
         */
        public RateBasis $rateBasis,
    ) {}

    /**
     * @throws InvalidArgumentException when the terms could not produce a schedule
     */
    public static function create(
        Money $principal,
        Percentage $interestRate,
        int $tenureDays,
        int $frequencyDays,
        CarbonImmutable $startDate,
        int $gracePeriodDays = 0,
        ?RateBasis $rateBasis = null,
    ): self {
        if (! $principal->isPositive()) {
            throw new InvalidArgumentException('A loan cannot be priced with a principal of zero or less.');
        }

        /*
         * Negative interest would pay the borrower to borrow.
         *
         * `Percentage` itself permits a negative — it is a general-purpose
         * signed rate, and other call sites legitimately need one (a refunded
         * fee, a downward adjustment). The refusal therefore belongs here, at
         * the loan boundary, rather than in the value object.
         *
         * `LoanProductRequest` already rejects it at the API edge. This is the
         * second line: a product created by a seeder, a console command or a
         * future import path never passes through that request, and would
         * otherwise produce a schedule with negative interest that balances
         * perfectly and is completely wrong.
         */
        if ($interestRate->thousandthsOfPercent < 0) {
            throw new InvalidArgumentException('A loan cannot be priced at a negative interest rate.');
        }

        if ($tenureDays < 1) {
            throw new InvalidArgumentException('A loan cannot be priced with a tenure shorter than a day.');
        }

        if ($frequencyDays < 1) {
            throw new InvalidArgumentException('A repayment frequency must be at least one day.');
        }

        if ($gracePeriodDays < 0) {
            throw new InvalidArgumentException('A grace period cannot be negative.');
        }

        return new self(
            principal: $principal,
            interestRate: $interestRate,
            tenureDays: $tenureDays,
            frequencyDays: $frequencyDays,
            gracePeriodDays: $gracePeriodDays,
            startDate: $startDate,
            rateBasis: $rateBasis ?? new AsConfiguredBasis,
        );
    }

    /**
     * The rate charged on ONE installment period.
     *
     * What FLAT and both reducing formulas ask for.
     */
    public function periodicRate(): Percentage
    {
        return $this->rateBasis->perPeriod($this->interestRate, $this->frequencyDays, $this->tenureDays);
    }

    /**
     * The rate charged ONCE across the whole tenure.
     *
     * What SIMPLE asks for.
     */
    public function tenureRate(): Percentage
    {
        return $this->rateBasis->perTenure($this->interestRate, $this->frequencyDays, $this->tenureDays);
    }

    /**
     * How many installments this tenure produces at this cadence.
     *
     * Rounded to nearest, never below one: a 45-day tenure on a monthly
     * schedule is two installments, and a tenure shorter than one period is
     * still repaid once. Lives here rather than in each strategy because every
     * formula must agree on how many installments a loan has — two strategies
     * disagreeing about that would produce two different loans from one
     * product.
     */
    public function installmentCount(): int
    {
        $quotient = intdiv($this->tenureDays, $this->frequencyDays);

        if (($this->tenureDays % $this->frequencyDays) * 2 >= $this->frequencyDays) {
            $quotient++;
        }

        return max(1, $quotient);
    }

    /**
     * When installment `$n` falls due.
     *
     * `n` periods after the start, plus the grace period. Shared for the same
     * reason as the count: a schedule whose dates depended on the formula would
     * make two products with identical cadence fall due on different days.
     */
    public function dueDate(int $installmentNumber): CarbonImmutable
    {
        return $this->startDate->addDays($this->gracePeriodDays + $this->frequencyDays * $installmentNumber);
    }

    /** The date the final installment falls due. */
    public function finalDueDate(): CarbonImmutable
    {
        return $this->dueDate($this->installmentCount());
    }
}
