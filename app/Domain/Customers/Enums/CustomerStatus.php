<?php

declare(strict_types=1);

namespace App\Domain\Customers\Enums;

/**
 * Mirrors the frontend's CUSTOMER_STATUSES and `customers.status` (spec §2.4).
 *
 * `frozen` is set only through the freeze workflow, which also writes an
 * `account_freezes` row (§2.1); it is never reachable from the plain
 * suspend/reactivate toggle.
 */
enum CustomerStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Frozen = 'frozen';

    /**
     * A frozen customer is blocked from new loans (§9); existing loans carry
     * on through their own state machine untouched.
     */
    public function blocksNewLoans(): bool
    {
        return $this !== self::Active;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
