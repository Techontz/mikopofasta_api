<?php

declare(strict_types=1);

namespace App\Domain\CustomerAdvances\DTOs;

use App\Support\Money;

/**
 * What one collection against an advance is made of.
 *
 * Three parts, because three different accounts are credited: the capital goes
 * back where it came from, and the interest and the fee are the profit. The
 * client states the rule as two buckets — "Principal → Operational Principal,
 * Profit → Operating Income" — and `profit()` is that second bucket; the split
 * into interest and fee below it is only so 2000 and 2100 each get their own.
 */
final readonly class AdvanceSplit
{
    public function __construct(
        public Money $principal,
        public Money $interest,
        public Money $fee,
    ) {}

    public static function zero(): self
    {
        return new self(Money::zero(), Money::zero(), Money::zero());
    }

    /** Interest plus charge fee — what the customer paid above the capital. */
    public function profit(): Money
    {
        return $this->interest->add($this->fee);
    }

    /** The whole collection. Equal to the payment, by construction. */
    public function total(): Money
    {
        return $this->principal->add($this->interest)->add($this->fee);
    }
}
