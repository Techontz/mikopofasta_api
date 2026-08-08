<?php

declare(strict_types=1);

namespace App\Domain\Hr\Services;

use Illuminate\Support\Facades\DB;

/**
 * Generates `staff_profiles.employee_number` in the legacy system's format:
 * EMP-0001.
 *
 * The frontend used to mint these itself; it no longer does, and this is now
 * the only place they are produced.
 *
 * Derived from the highest existing number rather than from MAX(id), for the
 * same reason as every other generator here: an auto-increment gap — a rolled
 * back transaction, a deleted row — would make the sequence skip visibly, and
 * an employee number that jumps looks like a missing employee.
 */
final class EmployeeNumberGenerator
{
    public const string PREFIX = 'EMP-';

    private const int PAD = 4;

    public function next(): string
    {
        $highest = (int) DB::table('staff_profiles')
            ->selectRaw('COALESCE(MAX(CAST(SUBSTRING(employee_number, ?) AS UNSIGNED)), 0) AS seq', [strlen(self::PREFIX) + 1])
            ->value('seq');

        return self::PREFIX.str_pad((string) ($highest + 1), self::PAD, '0', STR_PAD_LEFT);
    }
}
