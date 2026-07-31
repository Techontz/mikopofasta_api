<?php

declare(strict_types=1);

namespace App\Domain\Repayments\DTOs;

use App\Support\Money;

/**
 * The outcome of spreading one payment across a loan's schedule.
 *
 * `unallocated` is what remains after every outstanding installment is
 * settled — an overpayment. §7 is explicit that it "is **not** silently kept
 * in a schedule row"; the caller decides what happens to it.
 */
final readonly class AllocationResult
{
    /**
     * @param list<AllocationLine> $lines
     */
    public function __construct(
        public array $lines,
        public Money $unallocated,
    ) {}

    public function totalPenalty(): Money
    {
        return Money::sum(array_map(static fn (AllocationLine $l): Money => $l->penalty, $this->lines));
    }

    public function totalInterest(): Money
    {
        return Money::sum(array_map(static fn (AllocationLine $l): Money => $l->interest, $this->lines));
    }

    public function totalPrincipal(): Money
    {
        return Money::sum(array_map(static fn (AllocationLine $l): Money => $l->principal, $this->lines));
    }

    /**
     * What actually cleared debt — the figure the ledger posts, which is the
     * payment amount minus any overpayment.
     */
    public function allocatedTotal(): Money
    {
        return $this->totalPenalty()->add($this->totalInterest())->add($this->totalPrincipal());
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }
}
