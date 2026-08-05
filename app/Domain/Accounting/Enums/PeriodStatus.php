<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Enums;

/**
 * Whether an accounting period still accepts recognition — Decision Register D1.
 *
 * Only two states, and the transition is one-way. There is no "reopen": D1 puts
 * reserve appropriation inside the close, and reopening a period would mean
 * un-appropriating reserve that Admin may already have approved the use of.
 * A closed period that turns out to be wrong is corrected the way everything
 * else in this ledger is corrected — with a reversal in a later period, which
 * leaves both the error and the correction visible.
 */
enum PeriodStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    public function isClosed(): bool
    {
        return $this === self::Closed;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
