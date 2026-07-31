<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Enums;

/**
 * What a bank transaction does. Mirrors the frontend's `TRANSACTION_TYPES`.
 */
enum BankTransactionType: string
{
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';
    case Transfer = 'transfer';
    case Charge = 'charge';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'Deposit',
            self::Withdrawal => 'Withdrawal',
            self::Transfer => 'Transfer',
            self::Charge => 'Bank charge',
        };
    }

    /**
     * Whether the bank account gains money.
     *
     * A deposit is the only one that does. A withdrawal takes cash out to the
     * branch till, a charge is the bank's fee, and a transfer out is money
     * going elsewhere — all three reduce the balance.
     */
    public function increasesBalance(): bool
    {
        return $this === self::Deposit;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
