<?php

declare(strict_types=1);

namespace App\Domain\Customers\Enums;

/** Mirrors the frontend's MARITAL_STATUSES and `customers.marital_status`. */
enum MaritalStatus: string
{
    case Single = 'single';
    case Married = 'married';
    case Divorced = 'divorced';
    case Widowed = 'widowed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
