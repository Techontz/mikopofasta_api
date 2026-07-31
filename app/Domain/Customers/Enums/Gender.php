<?php

declare(strict_types=1);

namespace App\Domain\Customers\Enums;

/** Mirrors the frontend's GENDERS and `customers.gender` (spec §2.4). */
enum Gender: string
{
    case Male = 'male';
    case Female = 'female';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
