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

    /*
     * The automation's identity — client Decision 4.
     *
     * A status rather than a flag, because the login gate already asks
     * `canAuthenticate()`: making System a status means the account cannot log
     * in by construction, not because somebody remembered to check a boolean.
     *
     * Distinct from Suspended on purpose. A suspended account is a person who
     * has been stopped; a system account is not a person at all, and an
     * administrator scanning the user list needs to be able to tell the
     * difference before they try to "fix" it.
     */
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::System => 'System',
        };
    }

    /**
     * Only active users may authenticate. This mirrors the frontend's
     * findUserByPhone(), which filters on `status === "active"`.
     *
     * A System account never can — that is the whole point of it.
     */
    public function canAuthenticate(): bool
    {
        return $this === self::Active;
    }

    /** Whether this account represents a person at all. */
    public function isHuman(): bool
    {
        return $this !== self::System;
    }

    /**
     * The statuses an administrator may set on a person.
     *
     * Everything except System. Promoting a human account into the automation's
     * identity would give a real employee's row the status that hides it from
     * user administration and marks its postings as the system's — and on an
     * uninitialised database it would succeed, because the unique index only
     * stops the SECOND one.
     *
     * @return list<string>
     */
    public static function assignable(): array
    {
        return array_values(array_map(
            static fn (self $s): string => $s->value,
            array_filter(self::cases(), static fn (self $s): bool => $s !== self::System),
        ));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
