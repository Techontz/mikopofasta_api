<?php

declare(strict_types=1);

namespace App\Domain\Customers\Enums;

/**
 * A member's office within a group.
 *
 * The three offices are the group's own separation of duties: the secretary
 * keeps the register, the treasurer counts the cash, and the leader answers for
 * the group. One person may not hold two of them — see GroupService.
 */
enum GroupRole: string
{
    case Member = 'member';
    case Leader = 'leader';
    case Secretary = 'secretary';
    case Treasurer = 'treasurer';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The offices, as opposed to ordinary membership. At most one member of a
     * group may hold each of these.
     *
     * @return list<self>
     */
    public static function offices(): array
    {
        return [self::Leader, self::Secretary, self::Treasurer];
    }

    public function isOffice(): bool
    {
        return $this !== self::Member;
    }

    public function label(): string
    {
        return match ($this) {
            self::Member => 'Member',
            self::Leader => 'Leader',
            self::Secretary => 'Secretary',
            self::Treasurer => 'Treasurer',
        };
    }
}
