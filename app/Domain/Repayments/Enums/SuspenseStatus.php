<?php

declare(strict_types=1);

namespace App\Domain\Repayments\Enums;

/** Mirrors the frontend's SUSPENSE_STATUSES (§2.6). */
enum SuspenseStatus: string
{
    case Unallocated = 'unallocated';
    case Allocated = 'allocated';
    case Investigating = 'investigating';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
