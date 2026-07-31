<?php

declare(strict_types=1);

namespace App\Domain\Hr\Enums;

/** Mirrors the frontend's EMPLOYMENT_STATUSES and `staff_profiles.employment_status` (§2.9). */
enum EmploymentStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Terminated = 'terminated';

    /**
     * Only active staff appear on a payroll run.
     *
     * A suspended employee is still employed but is not paid this period; a
     * terminated one has left. Neither should acquire a payroll line, and
     * neither should draw a commission share.
     */
    public function isPayable(): bool
    {
        return $this === self::Active;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
