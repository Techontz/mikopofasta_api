<?php

declare(strict_types=1);

namespace App\Domain\Loans\DTOs;

use App\Support\Money;

/**
 * What it costs to close a loan today, and what the borrower is forgiven.
 *
 * A quote, not a decision: it is computed on demand and again at the moment of
 * settlement, so an officer can show a customer the figure and the figure they
 * are then charged is the same one. Nothing here is persisted — a stored quote
 * is a stale quote the day a penalty accrues.
 */
final readonly class EarlySettlementQuote
{
    public function __construct(
        /** Every penalty outstanding. Owed in full — none of it is unearned. */
        public Money $penalty,
        /** All remaining principal. The borrower is repaying the money. */
        public Money $principal,
        /** Interest on installments that have ALREADY fallen due — earned. */
        public Money $interestEarned,
        /** Interest on installments not yet due. Never earned, so waived. */
        public Money $interestWaived,
        /** Credit the borrower already holds, applied before any new cash. */
        public Money $advanceHeld,
        /** How many installments the settlement cancels. */
        public int $installmentsCancelled,
    ) {}

    /** The full figure the borrower owes to close today. */
    public function payable(): Money
    {
        return $this->penalty->add($this->principal)->add($this->interestEarned);
    }

    /**
     * What must still be tendered, after the advance is applied.
     *
     * Zero when the held credit already covers the settlement — which is the
     * ordinary case for a borrower who has been paying ahead.
     */
    public function cashRequired(): Money
    {
        $shortfall = $this->payable()->subtract($this->advanceHeld);

        return $shortfall->isPositive() ? $shortfall : Money::zero();
    }

    /** What the loan would have cost if it ran to term. */
    public function payableIfRunToTerm(): Money
    {
        return $this->payable()->add($this->interestWaived);
    }

    /** @return array<string, string|int> */
    public function toArray(): array
    {
        return [
            'penalty' => $this->penalty->toDecimalString(),
            'principal' => $this->principal->toDecimalString(),
            'interestEarned' => $this->interestEarned->toDecimalString(),
            'interestWaived' => $this->interestWaived->toDecimalString(),
            'advanceHeld' => $this->advanceHeld->toDecimalString(),
            'payable' => $this->payable()->toDecimalString(),
            'cashRequired' => $this->cashRequired()->toDecimalString(),
            'payableIfRunToTerm' => $this->payableIfRunToTerm()->toDecimalString(),
            'installmentsCancelled' => $this->installmentsCancelled,
        ];
    }
}
