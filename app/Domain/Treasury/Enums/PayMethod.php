<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Enums;

/**
 * How a capital contribution reached the company — Capital → Add Capitals.
 *
 * Decides which account the money is debited to: cash lands in the head-office
 * till, a cheque or transfer lands in the bank. The legacy screen offers the
 * same three under "Pay Method".
 */
enum PayMethod: string
{
    case Cash = 'cash';
    case Cheque = 'cheque';
    case BankTransfer = 'bank_transfer';

    /** True when the money physically enters a till rather than a bank account. */
    public function isCash(): bool
    {
        return $this === self::Cash;
    }

    /** A cheque number is only meaningful for one of these. */
    public function requiresChequeNumber(): bool
    {
        return $this === self::Cheque;
    }

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'CASH',
            self::Cheque => 'CHEQUE',
            self::BankTransfer => 'BANK TRANSFER',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
