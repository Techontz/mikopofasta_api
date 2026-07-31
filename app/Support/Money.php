<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An exact monetary amount, held as an integer number of minor units (cents).
 *
 * Every money column in the schema is DECIMAL(18,2) (spec §2). Nothing in this
 * class — or anywhere downstream of it — uses a float: binary floating point
 * cannot represent 0.1, so `0.1 + 0.2 !== 0.3`, and a ledger built on that
 * eventually fails to balance by a cent. Integers cannot drift.
 *
 * Rounding is half-up, matching both MySQL's DECIMAL rounding and the
 * frontend's `round2()` (`Math.round(x * 100) / 100`), so a schedule computed
 * here and one computed in the browser agree to the cent.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    /**
     * DECIMAL(18,2) — 16 integer digits, so the largest representable amount
     * is 9,999,999,999,999,999.99. Guarding the boundary means an overflow
     * surfaces here rather than as a silently truncated ledger row.
     */
    private const int MAX_MINOR = 999_999_999_999_999_999;

    private function __construct(public int $minor) {}

    public function __toString(): string
    {
        return $this->toDecimalString();
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * From an integer number of cents.
     */
    public static function ofMinor(int $minor): self
    {
        if (abs($minor) > self::MAX_MINOR) {
            throw new InvalidArgumentException('Amount exceeds DECIMAL(18,2).');
        }

        return new self($minor);
    }

    /**
     * From a decimal string such as "1500000.00" — the shape a DECIMAL column
     * and a JSON payload both arrive in.
     *
     * Accepts an int or a numeric string. A float is refused outright: by the
     * time a value is a float the precision loss has already happened, and
     * accepting it here would launder that error into the ledger.
     */
    public static function of(int|string $amount): self
    {
        if (is_int($amount)) {
            return self::ofMinor($amount * 100);
        }

        $trimmed = trim($amount);

        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,}))?$/', $trimmed, $matches) !== 1) {
            throw new InvalidArgumentException("[{$amount}] is not a valid decimal amount.");
        }

        $sign = $matches[1] === '-' ? -1 : 1;
        $whole = $matches[2];
        $fraction = $matches[3] ?? '';

        // More than two decimal places: round half-up on the third.
        $cents = (int) substr(str_pad($fraction, 3, '0'), 0, 2);

        if (strlen($fraction) > 2 && (int) $fraction[2] >= 5) {
            $cents++;
        }

        return self::ofMinor($sign * ((int) $whole * 100 + $cents));
    }

    public function add(self $other): self
    {
        return self::ofMinor($this->minor + $other->minor);
    }

    public function subtract(self $other): self
    {
        return self::ofMinor($this->minor - $other->minor);
    }

    public function multiply(int $factor): self
    {
        return self::ofMinor($this->minor * $factor);
    }

    /**
     * Divides into `$parts`, returning the exact quotient rounded half-up.
     *
     * Use `allocate()` instead when the parts must sum back to the original —
     * dividing 100.00 three ways gives 33.33 each, which is 99.99.
     */
    public function divide(int $parts): self
    {
        if ($parts === 0) {
            throw new InvalidArgumentException('Cannot divide money by zero.');
        }

        return self::ofMinor(self::divideRoundHalfUp($this->minor, $parts));
    }

    /**
     * Splits into `$parts` that sum EXACTLY back to this amount.
     *
     * The remainder cents are distributed one each across the earliest parts,
     * so nothing is created or destroyed. This is what keeps a repayment
     * schedule's principal column summing to the loan principal.
     *
     * @return list<self>
     */
    public function allocate(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('Cannot allocate money across fewer than one part.');
        }

        $base = intdiv($this->minor, $parts);
        $remainder = $this->minor - ($base * $parts);

        $result = [];

        for ($i = 0; $i < $parts; $i++) {
            $result[] = self::ofMinor($base + ($i < abs($remainder) ? ($remainder <=> 0) : 0));
        }

        return $result;
    }

    /**
     * `$percentage` of this amount, rounded half-up.
     */
    public function percentage(Percentage $percentage): self
    {
        return self::ofMinor(self::divideRoundHalfUp(
            $this->minor * $percentage->thousandthsOfPercent,
            Percentage::SCALE * 100,
        ));
    }

    /**
     * This amount scaled by the ratio `$numerator / $denominator`, rounded
     * half-up — a weighted share.
     *
     * Computed as `(this × numerator) ÷ denominator` in one step rather than
     * as a ratio first: rounding a ratio to two places and then multiplying
     * would scale the rounding error by the whole amount. Dividing a
     * commission pool by base-salary share is exactly that case.
     *
     * Not the same as `allocate()`. That splits an amount into equal parts
     * that sum back exactly; this answers "what is one weighted share",
     * independently of the others.
     */
    public function proportion(self $numerator, self $denominator): self
    {
        if ($denominator->minor === 0) {
            throw new InvalidArgumentException('Cannot take a proportion of a zero denominator.');
        }

        // The intermediate product is a plain PHP int; overflowing it would
        // silently become a float and take the precision this class exists to
        // protect. Guarded rather than risked.
        if ($numerator->minor !== 0 && abs($this->minor) > intdiv(PHP_INT_MAX, abs($numerator->minor))) {
            throw new InvalidArgumentException('Proportion overflows integer precision.');
        }

        return self::ofMinor(self::divideRoundHalfUp(
            $this->minor * $numerator->minor,
            $denominator->minor,
        ));
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor;
    }

    public function greaterThan(self $other): bool
    {
        return $this->minor > $other->minor;
    }

    public function lessThan(self $other): bool
    {
        return $this->minor < $other->minor;
    }

    public function min(self $other): self
    {
        return $this->minor <= $other->minor ? $this : $other;
    }

    public function max(self $other): self
    {
        return $this->minor >= $other->minor ? $this : $other;
    }

    /**
     * @param iterable<self> $amounts
     */
    public static function sum(iterable $amounts): self
    {
        $total = 0;

        foreach ($amounts as $amount) {
            $total += $amount->minor;
        }

        return self::ofMinor($total);
    }

    /**
     * The decimal string a DECIMAL(18,2) column stores, e.g. "1500000.00".
     */
    public function toDecimalString(): string
    {
        $sign = $this->minor < 0 ? '-' : '';
        $abs = abs($this->minor);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }

    public function jsonSerialize(): string
    {
        return $this->toDecimalString();
    }

    /**
     * Integer division rounding half away from zero.
     *
     * PHP's intdiv() truncates toward zero, so 250/100 would give 2 where the
     * frontend's Math.round gives 3 (for 2.5). Matching that behaviour is what
     * keeps the two schedule implementations byte-identical.
     */
    private static function divideRoundHalfUp(int $dividend, int $divisor): int
    {
        if ($divisor === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        $negative = ($dividend < 0) !== ($divisor < 0);
        $absDividend = abs($dividend);
        $absDivisor = abs($divisor);

        $quotient = intdiv($absDividend, $absDivisor);
        $remainder = $absDividend % $absDivisor;

        if ($remainder * 2 >= $absDivisor) {
            $quotient++;
        }

        return $negative ? -$quotient : $quotient;
    }
}
