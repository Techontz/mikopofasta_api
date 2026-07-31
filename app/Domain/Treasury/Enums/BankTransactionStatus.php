<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Enums;

/** Mirrors the frontend's `TRANSACTION_STATUSES` in types/bank.ts. */
enum BankTransactionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    /** Approval posts to the ledger, so only a pending row may be decided. */
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
