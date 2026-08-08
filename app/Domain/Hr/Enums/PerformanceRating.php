<?php

declare(strict_types=1);

namespace App\Domain\Hr\Enums;

/** Mirrors the frontend's PERFORMANCE_RATINGS and `staff_performance_records.rating` (§2.9). */
enum PerformanceRating: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
