<?php

declare(strict_types=1);

namespace App\Domain\Loans\Enums;

/** Mirrors the frontend's E_MANDATE_STATUSES and `e_mandates.status` (§2.5). */
enum EMandateStatus: string
{
    case PendingOtp = 'pending_otp';
    case Active = 'active';
    case Failed = 'failed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
