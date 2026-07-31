<?php

declare(strict_types=1);

namespace App\Domain\Loans\Enums;

/**
 * The three interest formulas from spec §5/§6 — mirrors the frontend's
 * INTEREST_FORMULA_CODES.
 *
 * What each one means mathematically is documented on LoanScheduleGenerator,
 * which is the single implementation.
 */
enum InterestFormulaCode: string
{
    case Simple = 'SIMPLE';
    case Flat = 'FLAT';
    case Reducing = 'REDUCING';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
