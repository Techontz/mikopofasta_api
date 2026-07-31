<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Mirrors the frontend's FREEZABLE_TYPES and `account_freezes.freezable_type`
 * (spec §2.1).
 *
 * Deliberately a short domain word ('customer') rather than a class name: the
 * spec types the column VARCHAR(60) with these exact values, and the frontend
 * seeds and filters on them.
 *
 * Loans and staff become freezable in Phases 5 and 7; the cases exist now
 * because the enum backs a single shared table.
 */
enum FreezableType: string
{
    case Customer = 'customer';
    case Loan = 'loan';
    case Staff = 'staff';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
