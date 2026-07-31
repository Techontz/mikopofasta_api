<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Enums;

/**
 * What a headquarters movement does to the headquarters position.
 *
 * Mirrors the frontend's `HqTransactionSchema["direction"]`, plus `internal`.
 *
 * No captured legacy screen has a direction column — both Headquater
 * Transaction screens were photographed empty — so this is the rebuilt
 * frontend's model rather than a transcription. It exists because
 * `hqBalance()` cannot separate income from expenditure without it.
 *
 * `internal` is the legacy module's original purpose: money moved between two
 * of the seven headquarters pots. It changes which pot holds the cash and not
 * how much there is in total, which is exactly why the balance calculation
 * counts only `in` and `out`.
 */
enum HqTransactionDirection: string
{
    case In = 'in';
    case Out = 'out';
    case Internal = 'internal';

    public function label(): string
    {
        return match ($this) {
            self::In => 'Money in',
            self::Out => 'Money out',
            self::Internal => 'Between accounts',
        };
    }

    /** Whether this movement changes the headquarters total at all. */
    public function affectsPosition(): bool
    {
        return $this !== self::Internal;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $d): string => $d->value, self::cases());
    }
}
