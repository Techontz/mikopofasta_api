<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Enums;

/** Mirrors the frontend's REVERSAL_STATUSES (§2.7). */
enum ReversalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
