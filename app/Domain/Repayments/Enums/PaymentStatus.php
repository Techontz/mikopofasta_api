<?php

declare(strict_types=1);

namespace App\Domain\Repayments\Enums;

/** Mirrors the frontend's PAYMENT_STATUSES and `payments.status` (§2.6). */
enum PaymentStatus: string
{
    case Received = 'received';
    case PendingVerification = 'pending_verification';
    case Unmatched = 'unmatched';
    case Allocated = 'allocated';
    case Confirmed = 'confirmed';
    case Reversed = 'reversed';
    case DuplicateFlagged = 'duplicate_flagged';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
