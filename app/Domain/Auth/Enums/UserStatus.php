<?php

declare(strict_types=1);

namespace App\Domain\Auth\Enums;

/**
 * Mirrors the frontend's USER_STATUSES (types/enums.ts) and the
 * `users.status` ENUM in backend spec §2.1.
 */
enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
        };
    }

    /**
     * Only active users may authenticate. This mirrors the frontend's
     * findUserByPhone(), which filters on `status === "active"`.
     */
    public function canAuthenticate(): bool
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
