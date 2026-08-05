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
     * How much of an existing advance credit this allocation spent.
     *
     * Held apart from the cash figures because it is not new money: the ledger
     * already recognised it when the borrower paid early. Posting it again
     * would recognise the same shilling twice, so the caller debits the advance
     * rather than cash for this portion.
     */
    public Money $advanceConsumed;

    /**
     * @param list<AllocationLine> $lines
     */
    public function __construct(
        public array $lines,
        public Money $unallocated,
        ?Money $advanceConsumed = null,
    ) {
        // Money's constructor is private, so zero cannot be a default argument.
        $this->advanceConsumed = $advanceConsumed ?? Money::zero();
    }

    /**
     * The cash this payment actually moved — what the ledger posts.
     *
     * The allocated total LESS whatever came out of the advance credit, because
     * that part was banked and recognised when it was first received.
     */
    public function cashApplied(): Money
    {
        return $this->allocatedTotal()->subtract($this->advanceConsumed);
    }

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
