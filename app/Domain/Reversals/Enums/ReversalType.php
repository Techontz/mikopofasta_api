<?php

declare(strict_types=1);

namespace App\Domain\Reversals\Enums;

/**
 * What a reversal request is asking to undo.
 *
 * The type decides which column of `reversal_requests` names the subject and
 * which executor runs on approval. It is NOT a presentation label: the five
 * cases behave differently enough that collapsing any two would mean an
 * executor guessing what it was handed.
 */
enum ReversalType: string
{
    /** A receipt against a loan — allocation, schedule, advances, loan status. */
    case Payment = 'payment';

    /** A payout that activated a loan — the loan returns to awaiting_disbursement. */
    case Disbursement = 'disbursement';

    /**
     * Accrued penalty on one installment. Alone among the four it has no
     * journal entry to mirror: §5 recognises penalty income when the penalty
     * is COLLECTED, so an uncollected penalty has never reached the books.
     * Approving one is a waiver, and `amount` is its only record.
     */
    case Penalty = 'penalty';

    /**
     * A collection against a customer salary advance. Its own case rather than
     * `payment`, because it names a different table and undoes a different
     * thing: no schedule, no allocation rows — the advance's repaid columns and
     * the entry that split the money between capital and profit.
     */
    case AdvancePayment = 'advance_payment';

    /** A bare journal entry no workflow owns — expenses, transfers, payroll. */
    case Ledger = 'ledger';

    public function label(): string
    {
        return match ($this) {
            self::Payment => 'Payment',
            self::Disbursement => 'Disbursement',
            self::Penalty => 'Penalty',
            self::AdvancePayment => 'Salary advance payment',
            self::Ledger => 'Journal entry',
        };
    }

    /** Penalty accrual never reaches the ledger, so nothing is mirrored. */
    public function postsReversalEntry(): bool
    {
        return $this !== self::Penalty;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
