<?php

declare(strict_types=1);

namespace App\Domain\Loans\Enums;

/**
 * The three interest formulas from spec §5/§6 — mirrors the frontend's
 * INTEREST_FORMULA_CODES.
 *
 * What each one means mathematically is documented on its strategy class in
 * which is the single implementation.
 *
 * ## NAMED CONSTANTS ONLY — this no longer constrains the database
 *
 * `interest_formulas.code` was an ENUM column cast to this enum, so adding a
 * formula meant a migration and a deploy. It is a free string now, and
 * InterestStrategyRegistry decides what can actually be priced: a code is valid
 * when a strategy implements it.
 *
 * What survives here is convenience — the three formulas the system seeds,
 * spelled once so seeders and tests do not repeat string literals. A formula an
 * administrator creates will never appear in this enum, and nothing requires it
 * to.
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
