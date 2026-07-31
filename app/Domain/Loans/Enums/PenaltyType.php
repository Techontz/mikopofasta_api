<?php

declare(strict_types=1);

namespace App\Domain\Loans\Enums;

/**
 * Mirrors the frontend's PENALTY_TYPES and `loan_products.penalty_type` (§2.3).
 *
 * The unit of `penalty_rate` DEPENDS on this value: for `flat_fee` it is an
 * amount in TZS, for the other two a percentage. The frontend makes the same
 * point in penalty.ts — rendering a 10,000 TZS flat fee as "10000%" is what
 * happens when the two are conflated.
 */
enum PenaltyType: string
{
    case PercentageOfOverdue = 'percentage_of_overdue';
    case FlatFee = 'flat_fee';
    case PercentagePerDay = 'percentage_per_day';

    /**
     * True when `penalty_rate` should be read as an amount rather than a rate.
     */
    public function rateIsAmount(): bool
    {
        return $this === self::FlatFee;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
