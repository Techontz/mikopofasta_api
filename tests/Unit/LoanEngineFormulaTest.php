<?php

declare(strict_types=1);

use App\Domain\Loans\Engine\InterestStrategyRegistry;
use App\Domain\Loans\Engine\LoanTerms;
use App\Domain\Loans\Engine\Strategies\FlatRateStrategy;
use App\Domain\Loans\Engine\Strategies\ReducingBalanceAnnuityStrategy;
use App\Domain\Loans\Engine\Strategies\ReducingBalanceStrategy;
use App\Domain\Loans\Engine\Strategies\SimpleInterestStrategy;
use App\Domain\Loans\Exceptions\UnknownInterestFormulaException;
use App\Support\Money;
use App\Support\Percentage;
use Carbon\CarbonImmutable;

/**
 * Mathematical verification of every interest formula.
 *
 * The expected figures here are computed BY HAND — from the client's own worked
 * examples where they gave them, and from the standard banking formulas
 * otherwise. They are written as literals on purpose. A test that recomputed
 * the expected value with the same code it is testing would pass no matter what
 * the code did; these fail if the arithmetic changes at all.
 *
 * Every schedule is additionally checked for the three invariants that must
 * hold whatever the formula: principal sums exactly to the loan, the balance
 * closes at exactly zero, and the installment count and dates match the terms.
 */
function terms(
    string $principal = '100000.00',
    string $rate = '10.000',
    int $tenureDays = 120,
    int $frequencyDays = 30,
    int $graceDays = 0,
    string $start = '2026-01-01',
): LoanTerms {
    return LoanTerms::create(
        principal: Money::of($principal),
        interestRate: Percentage::of($rate),
        tenureDays: $tenureDays,
        frequencyDays: $frequencyDays,
        startDate: CarbonImmutable::parse($start),
        gracePeriodDays: $graceDays,
    );
}

/** @return array{principal: string, interest: string, total: string} */
function totals(array $installments): array
{
    $principal = Money::sum(array_map(fn ($i) => $i->principalDue, $installments));
    $interest = Money::sum(array_map(fn ($i) => $i->interestDue, $installments));

    return [
        'principal' => $principal->toDecimalString(),
        'interest' => $interest->toDecimalString(),
        'total' => $principal->add($interest)->toDecimalString(),
    ];
}

/** Walks the schedule down from the principal; must land on exactly zero. */
function closingBalance(array $installments, string $principal): string
{
    $balance = Money::of($principal);

    foreach ($installments as $i) {
        $balance = $balance->subtract($i->principalDue);
    }

    return $balance->toDecimalString();
}

$simple = fn (): SimpleInterestStrategy => new SimpleInterestStrategy;
$flat = fn (): FlatRateStrategy => new FlatRateStrategy;
$reducing = fn (): ReducingBalanceStrategy => new ReducingBalanceStrategy;
$annuity = fn (): ReducingBalanceAnnuityStrategy => new ReducingBalanceAnnuityStrategy;

/* ══════════════════════ SIMPLE INTEREST ══════════════════════ */

describe('simple interest', function () use ($simple): void {
    it("matches the client's worked example exactly", function () use ($simple): void {
        /*
         * The client's example, verbatim:
         *   Principal 100,000 · Interest 20% · Interest 20,000 · Total 120,000
         *   Installment = 120,000 ÷ Duration
         *
         * Over 4 monthly installments: 30,000 each — 25,000 principal +
         * 5,000 interest.
         */
        $schedule = $simple()->schedule(terms(rate: '20.000', tenureDays: 120, frequencyDays: 30));

        expect($schedule)->toHaveCount(4)
            ->and(totals($schedule))->toBe([
                'principal' => '100000.00',
                'interest' => '20000.00',
                'total' => '120000.00',
            ]);

        foreach ($schedule as $i) {
            expect($i->principalDue->toDecimalString())->toBe('25000.00')
                ->and($i->interestDue->toDecimalString())->toBe('5000.00')
                ->and($i->total()->toDecimalString())->toBe('30000.00');
        }
    });

    it('charges the rate ONCE over the whole tenure, not per period', function () use ($simple): void {
        // 12 monthly installments at 20% must still be 20,000 of interest —
        // the defining difference from FLAT.
        $schedule = $simple()->schedule(terms(rate: '20.000', tenureDays: 360, frequencyDays: 30));

        expect($schedule)->toHaveCount(12)
            ->and(totals($schedule)['interest'])->toBe('20000.00');
    });

    it('spreads an indivisible interest total without losing a cent', function () use ($simple): void {
        // 100,000 × 10% = 10,000 over 3 installments = 3,333.33…
        $schedule = $simple()->schedule(terms(rate: '10.000', tenureDays: 90, frequencyDays: 30));

        expect(totals($schedule)['interest'])->toBe('10000.00')
            ->and($schedule[0]->interestDue->toDecimalString())->toBe('3333.34')
            ->and($schedule[1]->interestDue->toDecimalString())->toBe('3333.33')
            ->and($schedule[2]->interestDue->toDecimalString())->toBe('3333.33');
    });
});

/* ══════════════════════════ FLAT RATE ══════════════════════════ */

describe('flat rate', function () use ($flat): void {
    it("matches the client's worked example exactly", function () use ($flat): void {
        /*
         * The client's example, verbatim:
         *   Principal 100,000 · Rate 10% · Duration 5 months
         *   Interest = 100,000 × 10% × 5 = 50,000
         *   Total 150,000 · Monthly installment 150,000 ÷ 5 = 30,000
         */
        $schedule = $flat()->schedule(terms(rate: '10.000', tenureDays: 150, frequencyDays: 30));

        expect($schedule)->toHaveCount(5)
            ->and(totals($schedule))->toBe([
                'principal' => '100000.00',
                'interest' => '50000.00',
                'total' => '150000.00',
            ]);

        foreach ($schedule as $i) {
            expect($i->principalDue->toDecimalString())->toBe('20000.00')
                ->and($i->interestDue->toDecimalString())->toBe('10000.00')
                ->and($i->total()->toDecimalString())->toBe('30000.00');
        }
    });

    it('never reduces interest as principal is repaid', function () use ($flat): void {
        $schedule = $flat()->schedule(terms(rate: '5.000', tenureDays: 180, frequencyDays: 30));

        // Every period charges 5% of the ORIGINAL 100,000 — 5,000, unchanged.
        foreach ($schedule as $i) {
            expect($i->interestDue->toDecimalString())->toBe('5000.00');
        }

        expect(totals($schedule)['interest'])->toBe('30000.00');
    });
});

/* ═══════════════════ REDUCING BALANCE (equal principal) ═══════════════════ */

describe('reducing balance', function () use ($reducing, $flat): void {
    it('matches a hand-computed amortisation table', function () use ($reducing): void {
        /*
         * 100,000 · 10% per period · 4 periods, computed by hand:
         *
         *   #  Opening   Principal  Interest   Closing
         *   1  100,000      25,000    10,000    75,000
         *   2   75,000      25,000     7,500    50,000
         *   3   50,000      25,000     5,000    25,000
         *   4   25,000      25,000     2,500         0
         *                            -------
         *                             25,000
         */
        $schedule = $reducing()->schedule(terms(rate: '10.000', tenureDays: 120, frequencyDays: 30));

        $expected = [
            ['25000.00', '10000.00'],
            ['25000.00', '7500.00'],
            ['25000.00', '5000.00'],
            ['25000.00', '2500.00'],
        ];

        expect($schedule)->toHaveCount(4);

        foreach ($expected as $n => [$principal, $interest]) {
            expect($schedule[$n]->principalDue->toDecimalString())->toBe($principal)
                ->and($schedule[$n]->interestDue->toDecimalString())->toBe($interest);
        }

        expect(totals($schedule))->toBe([
            'principal' => '100000.00',
            'interest' => '25000.00',
            'total' => '125000.00',
        ]);
    });

    it('charges interest on the remaining principal at every step', function () use ($reducing): void {
        $schedule = $reducing()->schedule(terms(principal: '500000.00', rate: '2.000', tenureDays: 150, frequencyDays: 30));

        // Interest must strictly decrease — that is what "reducing" means.
        $previous = null;

        foreach ($schedule as $i) {
            if ($previous !== null) {
                expect($i->interestDue->lessThan($previous))->toBeTrue();
            }
            $previous = $i->interestDue;
        }

        // 2% of 500,000 / 400,000 / 300,000 / 200,000 / 100,000.
        expect(totals($schedule)['interest'])->toBe('30000.00');
    });

    it('costs the borrower less than flat at the same rate', function () use ($reducing, $flat): void {
        $t = terms(rate: '10.000', tenureDays: 120, frequencyDays: 30);

        expect(Money::of(totals($reducing()->schedule($t))['interest'])
            ->lessThan(Money::of(totals($flat()->schedule($t))['interest'])))->toBeTrue();
    });
});

/* ══════════════ REDUCING BALANCE — ANNUITY / EMI (the standard) ══════════════ */

describe('reducing balance annuity (EMI)', function () use ($annuity): void {
    it('computes the EMI from the standard banking formula', function () use ($annuity): void {
        /*
         * EMI = P × r(1+r)ⁿ / ((1+r)ⁿ − 1)
         *
         * P = 100,000 · r = 0.10 · n = 4
         * (1.1)⁴  = 1.4641
         * numerator   = 0.1 × 1.4641 = 0.14641
         * denominator = 0.4641
         * EMI = 100,000 × 0.315470803… = 31,547.08
         */
        expect($annuity()->equalInstallment(Money::of('100000.00'), '10.000', 4)->toDecimalString())
            ->toBe('31547.08');
    });

    it('matches a hand-computed amortisation table', function () use ($annuity): void {
        /*
         *   #  Opening      Interest   Principal   Closing
         *   1  100,000.00  10,000.00   21,547.08  78,452.92
         *   2   78,452.92   7,845.29   23,701.79  54,751.13
         *   3   54,751.13   5,475.11   26,071.97  28,679.16
         *   4   28,679.16   2,867.92   28,679.16          0   ← clears the balance
         */
        $schedule = $annuity()->schedule(terms(rate: '10.000', tenureDays: 120, frequencyDays: 30));

        $expected = [
            ['21547.08', '10000.00'],
            ['23701.79', '7845.29'],
            ['26071.97', '5475.11'],
            ['28679.16', '2867.92'],
        ];

        foreach ($expected as $n => [$principal, $interest]) {
            expect($schedule[$n]->principalDue->toDecimalString())->toBe($principal)
                ->and($schedule[$n]->interestDue->toDecimalString())->toBe($interest);
        }

        expect(closingBalance($schedule, '100000.00'))->toBe('0.00');
    });

    it('holds the instalment constant except for the final rounding', function () use ($annuity): void {
        $schedule = $annuity()->schedule(terms(principal: '1000000.00', rate: '1.500', tenureDays: 360, frequencyDays: 30));

        $first = $schedule[0]->total();

        // Every instalment but the last is the EMI to the cent.
        for ($n = 1; $n < count($schedule) - 1; $n++) {
            expect($schedule[$n]->total()->toDecimalString())->toBe($first->toDecimalString());
        }

        expect(closingBalance($schedule, '1000000.00'))->toBe('0.00');
    });

    it('does not divide by zero at a zero rate', function () use ($annuity): void {
        $schedule = $annuity()->schedule(terms(rate: '0.000', tenureDays: 120, frequencyDays: 30));

        expect(totals($schedule))->toBe([
            'principal' => '100000.00',
            'interest' => '0.00',
            'total' => '100000.00',
        ]);
    });
});

/* ════════════════ INVARIANTS ACROSS THE FULL SCENARIO MATRIX ════════════════ */

describe('invariants across every amount, duration and cadence', function () use ($simple, $flat, $reducing, $annuity): void {
    /** The client's required amounts and durations, times every formula. */
    $amounts = ['100000.00', '500000.00', '1000000.00', '5000000.00', '20000000.00'];
    $months = [1, 3, 6, 12, 24, 36];

    it('sums principal to exactly the loan and closes at exactly zero', function () use ($simple, $flat, $reducing, $annuity, $amounts, $months): void {
        $checked = 0;

        foreach ([$simple(), $flat(), $reducing(), $annuity()] as $strategy) {
            foreach ($amounts as $amount) {
                foreach ($months as $m) {
                    foreach (['3.500', '10.000', '18.750'] as $rate) {
                        $t = terms(principal: $amount, rate: $rate, tenureDays: $m * 30, frequencyDays: 30);
                        $schedule = $strategy->schedule($t);

                        expect($schedule)->toHaveCount($m);
                        expect(totals($schedule)['principal'])->toBe($amount);
                        expect(closingBalance($schedule, $amount))->toBe('0.00');

                        $checked++;
                    }
                }
            }
        }

        // 4 formulas × 5 amounts × 6 durations × 3 rates.
        expect($checked)->toBe(360);
    });

    it('holds at daily, weekly and monthly cadence', function () use ($simple, $flat, $reducing, $annuity): void {
        foreach ([$simple(), $flat(), $reducing(), $annuity()] as $strategy) {
            foreach ([1 => 30, 7 => 28, 30 => 360] as $frequency => $tenure) {
                $t = terms(principal: '750000.00', rate: '2.000', tenureDays: $tenure, frequencyDays: $frequency);
                $schedule = $strategy->schedule($t);

                expect(totals($schedule)['principal'])->toBe('750000.00')
                    ->and(closingBalance($schedule, '750000.00'))->toBe('0.00');
            }
        }
    });

    it('numbers installments from one, in order, with no gaps', function () use ($reducing): void {
        $schedule = $reducing()->schedule(terms(tenureDays: 360, frequencyDays: 30));

        foreach ($schedule as $index => $installment) {
            expect($installment->installmentNumber)->toBe($index + 1);
        }
    });

    it('spaces due dates by exactly one period', function () use ($flat): void {
        $schedule = $flat()->schedule(terms(tenureDays: 90, frequencyDays: 30, start: '2026-01-01'));

        expect($schedule[0]->dueDate->toDateString())->toBe('2026-01-31')
            ->and($schedule[1]->dueDate->toDateString())->toBe('2026-03-02')
            ->and($schedule[2]->dueDate->toDateString())->toBe('2026-04-01');
    });

    it('pushes every due date out by the grace period, without changing what is owed', function () use ($reducing): void {
        $without = $reducing()->schedule(terms(tenureDays: 90, frequencyDays: 30, graceDays: 0));
        $with = $reducing()->schedule(terms(tenureDays: 90, frequencyDays: 30, graceDays: 14));

        expect(totals($with))->toBe(totals($without));

        foreach ($with as $n => $installment) {
            expect($installment->dueDate->toDateString())
                ->toBe($without[$n]->dueDate->addDays(14)->toDateString());
        }
    });

    it('crosses a leap day without drifting', function () use ($flat): void {
        // 2028 is a leap year; the second period spans 29 February.
        $schedule = $flat()->schedule(terms(tenureDays: 90, frequencyDays: 30, start: '2028-01-15'));

        expect($schedule[0]->dueDate->toDateString())->toBe('2028-02-14')
            ->and($schedule[1]->dueDate->toDateString())->toBe('2028-03-15')
            ->and($schedule[2]->dueDate->toDateString())->toBe('2028-04-14');
    });
});

/* ═══════════════════════════ EDGE CASES ═══════════════════════════ */

describe('edge cases', function () use ($simple, $flat, $reducing, $annuity): void {
    it('produces a single installment for the minimum duration', function () use ($reducing): void {
        $schedule = $reducing()->schedule(terms(tenureDays: 1, frequencyDays: 30));

        expect($schedule)->toHaveCount(1)
            ->and($schedule[0]->principalDue->toDecimalString())->toBe('100000.00');
    });

    it('handles the maximum duration of 36 months daily', function () use ($reducing): void {
        $schedule = $reducing()->schedule(terms(principal: '20000000.00', rate: '0.050', tenureDays: 1080, frequencyDays: 1));

        expect($schedule)->toHaveCount(1080)
            ->and(closingBalance($schedule, '20000000.00'))->toBe('0.00');
    });

    it('handles the smallest meaningful loan', function () use ($simple, $flat, $reducing, $annuity): void {
        foreach ([$simple(), $flat(), $reducing(), $annuity()] as $strategy) {
            $schedule = $strategy->schedule(terms(principal: '0.01', rate: '10.000', tenureDays: 90, frequencyDays: 30));

            // One cent across three installments: 0.01 + 0.00 + 0.00.
            expect(totals($schedule)['principal'])->toBe('0.01')
                ->and(closingBalance($schedule, '0.01'))->toBe('0.00');
        }
    });

    it('handles a very large loan without losing precision', function () use ($reducing): void {
        $schedule = $reducing()->schedule(terms(principal: '99999999999.99', rate: '7.250', tenureDays: 720, frequencyDays: 30));

        expect(totals($schedule)['principal'])->toBe('99999999999.99')
            ->and(closingBalance($schedule, '99999999999.99'))->toBe('0.00');
    });

    it('charges nothing at a zero rate, whatever the formula', function () use ($simple, $flat, $reducing, $annuity): void {
        foreach ([$simple(), $flat(), $reducing(), $annuity()] as $strategy) {
            $schedule = $strategy->schedule(terms(rate: '0.000', tenureDays: 180, frequencyDays: 30));

            expect(totals($schedule))->toBe([
                'principal' => '100000.00',
                'interest' => '0.00',
                'total' => '100000.00',
            ]);
        }
    });

    it('refuses terms that cannot produce a schedule', function (): void {
        expect(fn () => terms(principal: '0.00'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => terms(principal: '-500.00'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => terms(tenureDays: 0))->toThrow(InvalidArgumentException::class)
            ->and(fn () => terms(frequencyDays: 0))->toThrow(InvalidArgumentException::class)
            ->and(fn () => terms(graceDays: -1))->toThrow(InvalidArgumentException::class);
    });

    it('refuses a negative interest rate at the loan boundary', function (): void {
        /*
         * `Percentage` itself PERMITS a negative — it is a general-purpose
         * signed rate and other call sites need one. Verification found that
         * nothing then stopped it reaching a strategy, where it would have
         * produced a perfectly balanced schedule with negative interest.
         *
         * LoanTerms now refuses it, which is why this asserts on the terms
         * rather than on the value object.
         */
        expect(Percentage::of('-1.000')->thousandthsOfPercent)->toBeLessThan(0);

        expect(fn () => terms(rate: '-1.000'))->toThrow(InvalidArgumentException::class);
    });
});

/* ═══════════════════════ THE REGISTRY / EXTENSIBILITY ═══════════════════════ */

describe('strategy registry', function () use ($simple, $flat, $reducing, $annuity): void {
    it('resolves every seeded formula code', function () use ($simple, $flat, $reducing, $annuity): void {
        $registry = new InterestStrategyRegistry([$simple(), $flat(), $reducing(), $annuity()]);

        expect($registry->codes())->toBe(['FLAT', 'REDUCING', 'REDUCING_EMI', 'SIMPLE']);
    });

    it('matches a code regardless of case or stray whitespace', function () use ($reducing): void {
        $registry = new InterestStrategyRegistry([$reducing()]);

        expect($registry->get('reducing'))->toBeInstanceOf(ReducingBalanceStrategy::class)
            ->and($registry->get('  Reducing '))->toBeInstanceOf(ReducingBalanceStrategy::class);
    });

    it('refuses an unimplemented formula instead of falling back', function () use ($simple): void {
        /*
         * The important negative. A registry that defaulted to SIMPLE for an
         * unknown code would price the loan by an arithmetic nobody chose, and
         * the schedule would look entirely ordinary.
         */
        $registry = new InterestStrategyRegistry([$simple()]);

        expect(fn () => $registry->get('RULE_OF_78'))
            ->toThrow(UnknownInterestFormulaException::class);
    });

    it('accepts a brand-new formula without the engine changing', function (): void {
        // The extensibility claim, exercised: an anonymous strategy is resolved
        // and run purely through the interface.
        $balloon = new class implements App\Domain\Loans\Engine\InterestStrategy
        {
            public function code(): string
            {
                return 'BALLOON';
            }

            public function describe(): string
            {
                return 'Interest only, with the whole principal due at the end.';
            }

            public function schedule(LoanTerms $terms): array
            {
                $count = $terms->installmentCount();
                $out = [];

                for ($i = 1; $i <= $count; $i++) {
                    $out[] = new App\Domain\Loans\DTOs\ScheduleInstallment(
                        installmentNumber: $i,
                        dueDate: $terms->dueDate($i),
                        principalDue: $i === $count ? $terms->principal : Money::zero(),
                        interestDue: $terms->principal->percentage($terms->interestRate),
                    );
                }

                return $out;
            }
        };

        $registry = new InterestStrategyRegistry([$balloon]);
        $schedule = $registry->get('BALLOON')->schedule(terms(rate: '5.000', tenureDays: 90, frequencyDays: 30));

        expect(totals($schedule))->toBe([
            'principal' => '100000.00',
            'interest' => '15000.00',
            'total' => '115000.00',
        ])->and($schedule[2]->principalDue->toDecimalString())->toBe('100000.00');
    });
});
