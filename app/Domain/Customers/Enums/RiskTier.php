<?php

declare(strict_types=1);

namespace App\Domain\Customers\Enums;

/** Mirrors the frontend's RISK_TIERS and `customer_categories.risk_tier` (§2.3). */
enum RiskTier: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
