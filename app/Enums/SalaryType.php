<?php

namespace App\Enums;

use App\Models\Role;

/**
 * Salary structures (STAFF COMMISSION §3): HQ staff are fixed, branch staff earn base +
 * commission, zone managers earn base + override commission from their zone's branches.
 */
enum SalaryType: string
{
    case Hq = 'hq';
    case Branch = 'branch';
    case ZoneManager = 'zone_manager';

    public function label(): string
    {
        return match ($this) {
            self::Hq => 'HQ Staff (Fixed)',
            self::Branch => 'Branch Staff (Base + Commission)',
            self::ZoneManager => 'Zone Manager (Base + Override)',
        };
    }

    /**
     * Default structure from the role's data scope (company → HQ, zone → zone manager, branch → branch staff).
     */
    public static function forRole(?Role $role): self
    {
        return match ($role?->scope) {
            'company' => self::Hq,
            'zone' => self::ZoneManager,
            default => self::Branch,
        };
    }
}
