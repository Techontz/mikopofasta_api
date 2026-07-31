<?php

declare(strict_types=1);

use App\Support\Money;
use App\Support\Percentage;

describe('Money', function (): void {
    it('parses decimal strings exactly', function (): void {
        expect(Money::of('1500000.00')->minor)->toBe(150_000_000)
            ->and(Money::of('0.01')->minor)->toBe(1)
            ->and(Money::of('100')->minor)->toBe(10_000)
            ->and(Money::of(100)->minor)->toBe(10_000)
            ->and(Money::of('-25.50')->minor)->toBe(-2_550);
    });

    it('rounds a third decimal place half-up', function (): void {
        expect(Money::of('1.005')->toDecimalString())->toBe('1.01')
            ->and(Money::of('1.004')->toDecimalString())->toBe('1.00');
    });

    it('refuses anything that is not a valid decimal', function (): void {
        expect(fn () => Money::of('abc'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => Money::of('1.2.3'))->toThrow(InvalidArgumentException::class);
    });

    it('adds and subtracts without drift', function (): void {
        // The canonical float failure: 0.1 + 0.2 !== 0.3 in binary floating
        // point. Integer minor units cannot express that error.
        $sum = Money::of('0.10')->add(Money::of('0.20'));

        expect($sum->equals(Money::of('0.30')))->toBeTrue()
            ->and($sum->toDecimalString())->toBe('0.30');
    });

    it('survives a long chain of additions exactly', function (): void {
        $total = Money::zero();

        for ($i = 0; $i < 1000; $i++) {
            $total = $total->add(Money::of('0.01'));
        }

        expect($total->toDecimalString())->toBe('10.00');
    });

    it('allocates so the parts always sum back to the whole', function (): void {
        // 100.00 / 3 is the classic case: 33.33 three times is 99.99.
        $parts = Money::of('100.00')->allocate(3);

        expect(array_map(fn (Money $m): string => $m->toDecimalString(), $parts))
            ->toBe(['33.34', '33.33', '33.33'])
            ->and(Money::sum($parts)->toDecimalString())->toBe('100.00');
    });

    it('allocates an amount smaller than the number of parts', function (): void {
        $parts = Money::of('0.02')->allocate(5);

        expect(Money::sum($parts)->toDecimalString())->toBe('0.02')
            ->and(array_map(fn (Money $m): int => $m->minor, $parts))->toBe([1, 1, 0, 0, 0]);
    });

    it('computes a percentage rounding half-up', function (): void {
        expect(Money::of('1000000.00')->percentage(Percentage::of('8.000'))->toDecimalString())
            ->toBe('80000.00')
            ->and(Money::of('100.00')->percentage(Percentage::of('12.500'))->toDecimalString())
            ->toBe('12.50')
            // 0.005 of 1.00 = 0.00005 -> rounds to 0.00
            ->and(Money::of('1.00')->percentage(Percentage::of('0.005'))->toDecimalString())
            ->toBe('0.00');
    });

    it('rounds division half away from zero, matching the frontend', function (): void {
        // The frontend uses Math.round, which rounds .5 away from zero;
        // PHP's intdiv truncates. Money::divide must follow Math.round or the
        // two schedule implementations diverge by a cent.
        expect(Money::ofMinor(5)->divide(2)->minor)->toBe(3)
            ->and(Money::ofMinor(-5)->divide(2)->minor)->toBe(-3);
    });

    it('refuses to overflow DECIMAL(18,2)', function (): void {
        expect(fn () => Money::ofMinor(PHP_INT_MAX))->toThrow(InvalidArgumentException::class);
    });

    it('round-trips through its decimal string', function (): void {
        foreach (['0.00', '0.01', '999.99', '1500000.00', '-25.50'] as $value) {
            expect(Money::of($value)->toDecimalString())->toBe($value);
        }
    });
});

describe('Percentage', function (): void {
    it('parses rates to thousandths of a percent', function (): void {
        expect(Percentage::of('8.000')->thousandthsOfPercent)->toBe(8_000)
            ->and(Percentage::of('0.075')->thousandthsOfPercent)->toBe(75)
            ->and(Percentage::of(12)->thousandthsOfPercent)->toBe(12_000);
    });

    it('round-trips through DECIMAL(6,3)', function (): void {
        foreach (['0.000', '8.000', '12.500', '999.999'] as $value) {
            expect(Percentage::of($value)->toDecimalString())->toBe($value);
        }
    });

    it('scales and divides exactly', function (): void {
        expect(Percentage::of('0.500')->times(30)->toDecimalString())->toBe('15.000')
            ->and(Percentage::of('12.000')->dividedBy(4)->toDecimalString())->toBe('3.000');
    });

    it('refuses a rate beyond DECIMAL(6,3)', function (): void {
        expect(fn () => Percentage::of('1000.000'))->toThrow(InvalidArgumentException::class);
    });
});
