<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The shared active/inactive status used across the configuration tables in
 * backend spec §2 — branches, bank accounts, loan products, chart of accounts,
 * groups. Mirrors the frontend's ACTIVE_INACTIVE (types/enums.ts).
 *
 * Distinct from Auth\Enums\UserStatus, which is active/suspended: suspending a
 * person and deactivating a facility are different things and the frontend
 * words them differently.
 */
enum ActiveStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
