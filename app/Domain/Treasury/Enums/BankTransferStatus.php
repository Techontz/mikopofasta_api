<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Enums;

/**
 * Mirrors the frontend's `TRANSFER_STATUSES` in types/bank.ts.
 *
 * Note this is `completed`, not `approved` — the transfer screens describe a
 * movement being carried out rather than a request being granted, and the
 * frontend's vocabulary is followed rather than normalised to match the other
 * queues. `cancelled` is its terminal failure, not `rejected`.
 */
enum BankTransferStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isDecidable(): bool
    {
        return $this === self::Pending;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
