<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Enums;

/**
 * The lifecycle of a float transfer. `Pending` posts nothing to the ledger —
 * money moves on approval, so a queue of requests never affects the trial
 * balance.
 */
enum FloatTransferStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function isSettled(): bool
    {
        return $this !== self::Pending;
    }

    public function label(): string
    {
        return strtoupper($this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
