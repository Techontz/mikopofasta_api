<?php

declare(strict_types=1);

use App\Domain\Loans\DTOs\ScheduleRequest;
use App\Domain\Loans\Enums\InterestFormulaCode;
use App\Domain\Loans\Services\LoanScheduleGenerator;
use App\Support\Money;
use App\Support\Percentage;
use Carbon\CarbonImmutable;

function scheduleRequest(
    string $principal = '1000000.00',
    string $rate = '8.000',
    int $tenureDays = 180,
    int $frequencyDays = 30,
    InterestFormulaCode $formula = InterestFormulaCode::Reducing,
    string $startDate = '2026-01-01',
): ScheduleRequest {
    return new ScheduleRequest(
        principal: Money::of($principal),
        interestRate: Percentage::of($rate),
        tenureDays: $tenureDays,
        frequencyDays: $frequencyDays,
        formula: $formula,
        startDate: CarbonImmutable::parse($startDate),
    );
}

function generator(): LoanScheduleGenerator
{
    return new LoanScheduleGenerator;
}

describe('installment count', function (): void {
    it('rounds to the nearest whole period and never returns zero', function (): void {
        $g = generator();

        expect($g->installmentCount(180, 30))->toBe(6)
            ->and($g->installmentCount(365, 30))->toBe(12)
            // 45/30 = 1.5, rounds up — matching the frontend's Math.round.
            ->and($g->installmentCount(45, 30))->toBe(2)
            ->and($g->installmentCount(44, 30))->toBe(1)
            // A tenure shorter than one period still has to be repaid once.
            ->and($g->installmentCount(10, 30))->toBe(1)
            ->and($g->installmentCount(90, 7))->toBe(13)
            ->and($g->installmentCount(30, 1))->toBe(30);
    });
});

describe('principal exactness', function (): void {
    it('always sums principal back to the loan principal, for every formula', function (): void {
        foreach (InterestFormulaCode::cases() as $formula) {
            foreach (['1000000.00', '333333.33', '100.00', '0.05'] as $principal) {
                foreach ([[180, 30], [365, 30], [90, 7], [30, 1]] as [$tenure, $frequency]) {
                    $request = scheduleRequest(
                        principal: $principal,
                        tenureDays: $tenure,
                        frequencyDays: $frequency,
                        formula: $formula,
                    );

                    $installments = generator()->generate($request);
                    $total = Money::sum(array_map(fn ($i) => $i->principalDue, $installments));

                    expect($total->toDecimalString())->toBe(
                        Money::of($principal)->toDecimalString(),
                        "{$formula->value} {$principal} over {$tenure}d/{$frequency}d must not lose a cent",
                    );
                }
            }
        }
    });

    it('absorbs the rounding remainder rather than dropping it', function (): void {
        // 100.00 over 3 installments: 33.34 + 33.33 + 33.33.
        $installments = generator()->generate(scheduleRequest(
            principal: '100.00',
            tenureDays: 90,
            frequencyDays: 30,
            formula: InterestFormulaCode::Flat,
        ));

        expect(array_map(fn ($i): string => $i->principalDue->toDecimalString(), $installments))
            ->toBe(['33.34', '33.33', '33.33']);
    });
});

describe('REDUCING', function (): void {
    it('charges interest on the declining balance', function (): void {
        // 1,000,000 at 8% over 6 monthly installments.
        $installments = generator()->generate(scheduleRequest(formula: InterestFormulaCode::Reducing));

        expect($installments)->toHaveCount(6);

        // First installment: 8% of the full 1,000,000.
        expect($installments[0]->interestDue->toDecimalString())->toBe('80000.00')
            ->and($installments[0]->principalDue->toDecimalString())->toBe('166666.67');

        // Second: 8% of what is left after the first principal repayment.
        expect($installments[1]->interestDue->toDecimalString())->toBe('66666.67');

        // Interest falls monotonically — that is the whole point of REDUCING.
        $previous = null;
        foreach ($installments as $installment) {
            if ($previous !== null) {
                expect($installment->interestDue->minor)->toBeLessThan($previous);
            }
            $previous = $installment->interestDue->minor;
        }
    });

    it('leaves no outstanding principal after the final installment', function (): void {
        $installments = generator()->generate(scheduleRequest(principal: '999999.99', formula: InterestFormulaCode::Reducing));

        expect(Money::sum(array_map(fn ($i) => $i->principalDue, $installments))->toDecimalString())
            ->toBe('999999.99');
    });
});

describe('FLAT', function (): void {
    it('charges the rate per installment on the original principal', function (): void {
        // 3% per installment on 1,000,000, six installments.
        $installments = generator()->generate(scheduleRequest(
            rate: '3.000',
            formula: InterestFormulaCode::Flat,
        ));

        foreach ($installments as $installment) {
            // Constant: interest does not shrink as principal is paid down.
            expect($installment->interestDue->toDecimalString())->toBe('30000.00');
        }

        expect(Money::sum(array_map(fn ($i) => $i->interestDue, $installments))->toDecimalString())
            ->toBe('180000.00');
    });
});

describe('SIMPLE', function (): void {
    it('spreads one whole-tenure charge across the installments', function (): void {
        // 12% of 1,000,000 = 120,000 total, over 6 installments = 20,000 each.
        $installments = generator()->generate(scheduleRequest(
            rate: '12.000',
            formula: InterestFormulaCode::Simple,
        ));

        foreach ($installments as $installment) {
            expect($installment->interestDue->toDecimalString())->toBe('20000.00');
        }

        expect(Money::sum(array_map(fn ($i) => $i->interestDue, $installments))->toDecimalString())
            ->toBe('120000.00');
    });

    it('charges less total interest than FLAT at the same rate', function (): void {
        $simple = generator()->generate(scheduleRequest(rate: '6.000', formula: InterestFormulaCode::Simple));
        $flat = generator()->generate(scheduleRequest(rate: '6.000', formula: InterestFormulaCode::Flat));

        // SIMPLE divides one charge across installments; FLAT repeats it.
        expect(Money::sum(array_map(fn ($i) => $i->interestDue, $simple))->minor)
            ->toBeLessThan(Money::sum(array_map(fn ($i) => $i->interestDue, $flat))->minor);
    });
});

describe('due dates', function (): void {
    it('places installment n exactly n periods after the start date', function (): void {
        $installments = generator()->generate(scheduleRequest(startDate: '2026-01-01'));

        expect($installments[0]->dueDate->toDateString())->toBe('2026-01-31')
            ->and($installments[1]->dueDate->toDateString())->toBe('2026-03-02')
            ->and($installments[5]->dueDate->toDateString())->toBe('2026-06-30');
    });

    it('reports the expected completion date as the final due date', function (): void {
        $request = scheduleRequest(startDate: '2026-01-01');
        $installments = generator()->generate($request);

        expect(generator()->expectedCompletionDate($request)->toDateString())
            ->toBe(end($installments)->dueDate->toDateString());
    });

    it('handles a daily schedule', function (): void {
        $installments = generator()->generate(scheduleRequest(tenureDays: 30, frequencyDays: 1, startDate: '2026-01-01'));

        expect($installments)->toHaveCount(30)
            ->and($installments[0]->dueDate->toDateString())->toBe('2026-01-02')
            ->and($installments[29]->dueDate->toDateString())->toBe('2026-01-31');
    });
});

describe('determinism', function (): void {
    it('produces byte-identical output for identical input', function (): void {
        $request = scheduleRequest();

        $first = generator()->generate($request);
        $second = generator()->generate($request);

        $flatten = fn (array $rows): array => array_map(fn ($i): array => [
            $i->installmentNumber,
            $i->dueDate->toDateString(),
            $i->principalDue->toDecimalString(),
            $i->interestDue->toDecimalString(),
        ], $rows);

        expect($flatten($first))->toBe($flatten($second));
    });

    it('never produces a negative or fractional-cent amount', function (): void {
        foreach (InterestFormulaCode::cases() as $formula) {
            $installments = generator()->generate(scheduleRequest(principal: '777777.77', formula: $formula));

            foreach ($installments as $installment) {
                expect($installment->principalDue->isNegative())->toBeFalse()
                    ->and($installment->interestDue->isNegative())->toBeFalse();
            }
        }
    });

    it('handles a zero interest rate', function (): void {
        $installments = generator()->generate(scheduleRequest(rate: '0.000', formula: InterestFormulaCode::Reducing));

        foreach ($installments as $installment) {
            expect($installment->interestDue->isZero())->toBeTrue();
        }
    });
});
