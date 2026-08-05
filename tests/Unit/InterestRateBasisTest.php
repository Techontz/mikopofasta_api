<?php

declare(strict_types=1);

use App\Domain\Loans\Engine\Bases\AsConfiguredBasis;
use App\Domain\Loans\Engine\Bases\PerAnnumBasis;
use App\Domain\Loans\Engine\LoanEngine;
use App\Domain\Loans\Engine\LoanTerms;
use App\Domain\Loans\Engine\RateBasisRegistry;
use App\Domain\Loans\Exceptions\UnknownRateBasisException;
use App\Support\Money;
use App\Support\Percentage;
use Carbon\CarbonImmutable;

/**
 * P2 — what the number in `interest_rate` means — as a switchable policy.
 *
 * The client's instruction was explicit: "DO NOT implement any assumption.
 * Leave this configurable... design the architecture so either option can be
 * enabled later without changing the loan engine."
 *
 * So there are two things to prove, and they pull in opposite directions:
 *
 *   1. Introducing the mechanism changed NOTHING. The default basis is a no-op,
 *      and every existing schedule prices identically.
 *   2. The other option genuinely works, and enabling it is a data change.
 *
 * Expected values are written as literals, computed by hand.
 */
function basisTerms(string $rate, int $tenureDays, int $frequencyDays, ?App\Domain\Loans\Engine\RateBasis $basis = null): LoanTerms
{
    return LoanTerms::create(
        principal: Money::of('1000000.00'),
        interestRate: Percentage::of($rate),
        tenureDays: $tenureDays,
        frequencyDays: $frequencyDays,
        startDate: CarbonImmutable::parse('2026-01-01'),
        rateBasis: $basis,
    );
}

describe('the default basis changes nothing', function (): void {
    it('returns the configured rate untouched for both spans', function (): void {
        $terms = basisTerms('4.500', 360, 30);

        expect($terms->periodicRate()->toDecimalString())->toBe('4.500')
            ->and($terms->tenureRate()->toDecimalString())->toBe('4.500')
            ->and($terms->interestRate->toDecimalString())->toBe('4.500');
    });

    it('is what a loan gets when no basis is configured at all', function (): void {
        // Null is what every product row predating the decision carries.
        expect(app(RateBasisRegistry::class)->get(null))->toBeInstanceOf(AsConfiguredBasis::class)
            ->and(app(RateBasisRegistry::class)->get('')->code())->toBe(AsConfiguredBasis::CODE);
    });

    it('reproduces the client\'s own worked examples exactly', function (): void {
        $engine = app(LoanEngine::class);

        // 100,000 at 20% simple → 20,000 interest, 120,000 total, four of 30,000.
        $simple = $engine->scheduleFor(
            LoanTerms::create(
                principal: Money::of('100000.00'),
                interestRate: Percentage::of('20.000'),
                tenureDays: 120,
                frequencyDays: 30,
                startDate: CarbonImmutable::parse('2026-01-01'),
            ),
            'SIMPLE',
        );

        $interest = Money::sum(array_map(fn ($i) => $i->interestDue, $simple));

        expect($interest->toDecimalString())->toBe('20000.00')
            ->and(count($simple))->toBe(4);
    });
});

describe('per annum, when the client enables it', function (): void {
    it('pro-rates an annual rate to a monthly cadence', function (): void {
        /*
         * 24% per annum on a 30-day cadence.
         *
         *     24.000 × 30 / 365 = 1.9726…  →  1.973 at DECIMAL(6,3)
         */
        $terms = basisTerms('24.000', 360, 30, new PerAnnumBasis);

        expect($terms->periodicRate()->toDecimalString())->toBe('1.973');
    });

    it('pro-rates the same rate across a whole tenure', function (): void {
        // 24.000 × 90 / 365 = 5.9178… → 5.918
        $terms = basisTerms('24.000', 90, 30, new PerAnnumBasis);

        expect($terms->tenureRate()->toDecimalString())->toBe('5.918');
    });

    it('survives a tenure long enough to overflow a naive multiply', function (): void {
        /*
         * The reason Percentage::scaledBy() exists. 24% × 1080 days is 25,920%,
         * far beyond a storable rate — multiplying first and dividing after
         * would throw before the division brought it back to 71.014%.
         */
        $terms = basisTerms('24.000', 1080, 30, new PerAnnumBasis);

        expect($terms->tenureRate()->toDecimalString())->toBe('71.014');
    });

    it('uses a fixed 365 so a leap year does not reprice a product', function (): void {
        $ordinary = basisTerms('18.000', 365, 30, new PerAnnumBasis)->tenureRate();
        $leap = LoanTerms::create(
            principal: Money::of('1000000.00'),
            interestRate: Percentage::of('18.000'),
            tenureDays: 365,
            frequencyDays: 30,
            startDate: CarbonImmutable::parse('2028-01-01'),
            rateBasis: new PerAnnumBasis,
        )->tenureRate();

        expect($leap->toDecimalString())->toBe($ordinary->toDecimalString());
    });

    it('charges materially less than the same figure read per period', function (): void {
        /*
         * The whole reason P2 matters. 24% read per month is twelve times 24%
         * read per year, and pricing the portfolio on the wrong reading would
         * be an order-of-magnitude error that produces a perfectly ordinary
         * looking schedule.
         */
        $engine = app(LoanEngine::class);

        $perPeriod = $engine->scheduleFor(basisTerms('24.000', 360, 30), 'FLAT');
        $perAnnum = $engine->scheduleFor(basisTerms('24.000', 360, 30, new PerAnnumBasis), 'FLAT');

        $interestOf = fn (array $s): Money => Money::sum(array_map(fn ($i) => $i->interestDue, $s));

        expect($interestOf($perAnnum)->lessThan($interestOf($perPeriod)))->toBeTrue();
    });

    it('still closes the balance at exactly zero under every formula', function (): void {
        $engine = app(LoanEngine::class);

        foreach (['SIMPLE', 'FLAT', 'REDUCING', 'REDUCING_EMI'] as $formula) {
            $schedule = $engine->scheduleFor(basisTerms('24.000', 360, 30, new PerAnnumBasis), $formula);

            $principal = Money::sum(array_map(fn ($i) => $i->principalDue, $schedule));

            expect($principal->toDecimalString())->toBe('1000000.00');
        }
    });
});

describe('the registry', function (): void {
    it('resolves both implemented bases, case-insensitively', function (): void {
        $registry = app(RateBasisRegistry::class);

        expect($registry->get('as_configured')->code())->toBe(AsConfiguredBasis::CODE)
            ->and($registry->get('  per_annum ')->code())->toBe(PerAnnumBasis::CODE);
    });

    it('refuses a basis nothing implements rather than defaulting', function (): void {
        /*
         * A fallback here would misprice by a factor of twelve while producing
         * a schedule that looks entirely normal — the same reasoning as the
         * interest formula registry.
         */
        expect(fn () => app(RateBasisRegistry::class)->get('COMPOUNDED_DAILY'))
            ->toThrow(UnknownRateBasisException::class);
    });
});
