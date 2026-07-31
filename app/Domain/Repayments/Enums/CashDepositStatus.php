<?php

declare(strict_types=1);

namespace App\Domain\Repayments\Enums;

/** Mirrors the frontend's CASH_DEPOSIT_STATUSES (§2.6). */
enum CashDepositStatus: string
{
    case Pending = 'pending';
    case Matched = 'matched';
    case Confirmed = 'confirmed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
