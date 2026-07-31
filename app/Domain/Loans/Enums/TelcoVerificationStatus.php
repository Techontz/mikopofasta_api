<?php

declare(strict_types=1);

namespace App\Domain\Loans\Enums;

/** Mirrors the frontend's TELCO_VERIFICATION_STATUSES (§2.5). */
enum TelcoVerificationStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
