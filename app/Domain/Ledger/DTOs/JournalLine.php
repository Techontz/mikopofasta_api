<?php

declare(strict_types=1);

namespace App\Domain\Ledger\DTOs;

use App\Support\Money;
use InvalidArgumentException;

/**
 * One side of a journal entry, before it is posted.
 *
 * A line is either a debit or a credit, never both and never neither — that is
 * enforced in the constructor rather than left to the posting engine, so an
 * incoherent line cannot exist even momentarily.
 *
 * The four optional dimensions are what make §2.7's derived sub-ledgers work:
 * customer, loan, staff and branch "ledgers" are these lines filtered by the
 * matching id, not separate tables.
 */
final readonly class JournalLine
{
    private function __construct(
        public int $accountId,
        public Money $debit,
        public Money $credit,
        public ?int $branchId = null,
        public ?int $customerId = null,
        public ?int $loanId = null,
        public ?int $staffProfileId = null,
    ) {}

    public static function debit(
        int $accountId,
        Money $amount,
        ?int $branchId = null,
        ?int $customerId = null,
        ?int $loanId = null,
        ?int $staffProfileId = null,
    ): self {
        self::guardAmount($amount);

        return new self($accountId, $amount, Money::zero(), $branchId, $customerId, $loanId, $staffProfileId);
    }

    public static function credit(
        int $accountId,
        Money $amount,
        ?int $branchId = null,
        ?int $customerId = null,
        ?int $loanId = null,
        ?int $staffProfileId = null,
    ): self {
        self::guardAmount($amount);

        return new self($accountId, Money::zero(), $amount, $branchId, $customerId, $loanId, $staffProfileId);
    }

    public function isDebit(): bool
    {
        return $this->debit->isPositive();
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabaseRow(int $journalEntryId): array
    {
        return [
            'journal_entry_id' => $journalEntryId,
            'account_id' => $this->accountId,
            'debit_amount' => $this->debit->toDecimalString(),
            'credit_amount' => $this->credit->toDecimalString(),
            'branch_id' => $this->branchId,
            'customer_id' => $this->customerId,
            'loan_id' => $this->loanId,
            'staff_profile_id' => $this->staffProfileId,
        ];
    }

    /**
     * A zero or negative line is always a bug: it is either a calculation that
     * produced nothing (and should have been skipped) or a sign error that
     * would post money in the wrong direction.
     */
    private static function guardAmount(Money $amount): void
    {
        if (! $amount->isPositive()) {
            throw new InvalidArgumentException(
                'A journal line must carry a positive amount; got '.$amount->toDecimalString().'.',
            );
        }
    }
}
