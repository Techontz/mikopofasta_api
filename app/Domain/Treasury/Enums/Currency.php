<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Enums;

/**
 * Currencies a bank account can be held in.
 *
 * Mirrors the frontend's `CURRENCIES` in types/bank.ts. TZS is the operating
 * currency and the default; the other three appear on the Register Account
 * form's dropdown.
 *
 * Note that nothing converts between them. Every amount in the ledger is in
 * the account's own currency, and a system that held two and pretended
 * otherwise would be worse than one that held one — so a non-TZS account is
 * recorded and reported, not translated.
 */
enum Currency: string
{
    case Tzs = 'TZS';
    case Usd = 'USD';
    case Kes = 'KES';
    case Ugx = 'UGX';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
