<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Enums;

/**
 * Which Transfer Balance screen raised a transfer.
 *
 * Mirrors the frontend's `TRANSFER_KINDS`. The two differ in where the money
 * goes, which is why `bank_transfers` has two destination columns and each kind
 * uses exactly one:
 *
 *   Branch          — bank account to a branch's teller cash.
 *   SalaryAdvance   — bank account to another bank account, the one salary
 *                     advances and disbursements are paid from.
 */
enum BankTransferKind: string
{
    case Branch = 'branch';
    case SalaryAdvance = 'salary_advance';

    public function label(): string
    {
        return match ($this) {
            self::Branch => 'Branch account',
            self::SalaryAdvance => 'Salary advance & disbursement account',
        };
    }

    /** Whether the destination is a branch till rather than another account. */
    public function targetsBranch(): bool
    {
        return $this === self::Branch;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $k): string => $k->value, self::cases());
    }
}
