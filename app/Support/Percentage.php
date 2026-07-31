<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use Stringable;

/**
 * An exact percentage, held as an integer number of thousandths of a percent.
 *
 * Every rate column in the schema is DECIMAL(6,3) — `interest_rate`,
 * `penalty_rate`, `pool_percentage`, `override_percentage` (spec §2.3, §2.9).
 * Three decimal places is exactly one thousandth of a percent, so an integer
 * count of those represents any storable rate without loss.
 *
 * 8.000%  → 8_000
 * 0.075%  → 75
 * 12.500% → 12_500
 *
 * As with Money, floats are refused: a rate that has already passed through a
 * float has already lost precision, and every interest figure derived from it
 * would inherit that error.
 */
final readonly class Percentage implements Stringable
{
    /**
     * Thousandths per whole percent — the 3 in DECIMAL(6,3).
     */
    public const int SCALE = 1_000;

    /**
     * DECIMAL(6,3) holds at most 999.999.
     */
    private const int MAX = 999_999;

    private function __construct(public int $thousandthsOfPercent) {}

    public function __toString(): string
    {
        return $this->toDecimalString();
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public static function ofThousandths(int $thousandths): self
    {
        if (abs($thousandths) > self::MAX) {
            throw new InvalidArgumentException('Rate exceeds DECIMAL(6,3).');
        }

        return new self($thousandths);
    }

    /**
     * From a decimal string such as "8.000" or an integer percent.
     */
    public static function of(int|string $percent): self
    {
        if (is_int($percent)) {
            return self::ofThousandths($percent * self::SCALE);
        }

        $trimmed = trim($percent);

        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,}))?$/', $trimmed, $matches) !== 1) {
            throw new InvalidArgumentException("[{$percent}] is not a valid rate.");
        }

        $sign = $matches[1] === '-' ? -1 : 1;
        $whole = (int) $matches[2];
        $fraction = $matches[3] ?? '';

        $thousandths = (int) substr(str_pad($fraction, 4, '0'), 0, 3);

        if (strlen($fraction) > 3 && (int) $fraction[3] >= 5) {
            $thousandths++;
        }

        return self::ofThousandths($sign * ($whole * self::SCALE + $thousandths));
    }

    public function isZero(): bool
    {
        return $this->thousandthsOfPercent === 0;
    }

    /**
     * Scales the rate by a whole number — used where a rate applies per day
     * across several days (`percentage_per_day` penalties, §2.3).
     */
    public function times(int $factor): self
    {
        return self::ofThousandths($this->thousandthsOfPercent * $factor);
    }

    /**
     * Divides the rate into equal parts, rounding half-up.
     */
    public function dividedBy(int $parts): self
    {
        if ($parts === 0) {
            throw new InvalidArgumentException('Cannot divide a rate by zero.');
        }

        $negative = ($this->thousandthsOfPercent < 0) !== ($parts < 0);
        $abs = abs($this->thousandthsOfPercent);
        $absParts = abs($parts);

        $quotient = intdiv($abs, $absParts);

        if (($abs % $absParts) * 2 >= $absParts) {
            $quotient++;
        }

        return self::ofThousandths($negative ? -$quotient : $quotient);
    }

    /**
     * The decimal string a DECIMAL(6,3) column stores, e.g. "8.000".
     */
    public function toDecimalString(): string
    {
        $sign = $this->thousandthsOfPercent < 0 ? '-' : '';
        $abs = abs($this->thousandthsOfPercent);

        return sprintf('%s%d.%03d', $sign, intdiv($abs, self::SCALE), $abs % self::SCALE);
    }
}
