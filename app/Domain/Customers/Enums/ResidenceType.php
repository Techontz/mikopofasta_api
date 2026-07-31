<?php

declare(strict_types=1);

namespace App\Domain\Customers\Enums;

/** Mirrors the frontend's RESIDENCE_TYPES and `customers.residence_type`. */
enum ResidenceType: string
{
    case Owned = 'owned';
    case Rented = 'rented';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
