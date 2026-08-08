<?php

declare(strict_types=1);

namespace App\Domain\Hr\Enums;

/** Mirrors the frontend's STAFF_PAYMENT_METHODS and `staff_profiles.payment_method` (§2.9). */
enum StaffPaymentMethod: string
{
    case Bank = 'bank';
    case Mobile = 'mobile';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
