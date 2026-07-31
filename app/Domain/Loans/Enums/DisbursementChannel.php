<?php

declare(strict_types=1);

namespace App\Domain\Loans\Enums;

/** Mirrors the frontend's DISBURSEMENT_CHANNELS (§2.5). */
enum DisbursementChannel: string
{
    case Vodacom = 'vodacom';
    case Airtel = 'airtel';
    case Bank = 'bank';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
