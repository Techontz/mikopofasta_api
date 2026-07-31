<?php

declare(strict_types=1);

namespace App\Domain\Customers\Enums;

/**
 * Mirrors the frontend's CUSTOMER_CATEGORY_SECTORS.
 *
 * Not in backend spec §2.3 — the frontend adds it purely to decide whether the
 * registration wizard labels its dynamic step "Employment Details" or
 * "Business Information". It carries no rule of its own.
 */
enum CategorySector: string
{
    case Employment = 'employment';
    case Business = 'business';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
