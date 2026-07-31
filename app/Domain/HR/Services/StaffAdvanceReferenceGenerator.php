<?php

declare(strict_types=1);

namespace App\Domain\Hr\Services;

use Illuminate\Support\Facades\DB;

/**
 * ADV-0000001 upward — the reference the Salary Advance screens print on every
 * row.
 *
 * Derived from the highest reference in use rather than from a row count, the
 * same rule every other generator here follows: a soft-deleted advance keeps
 * its reference, and counting would hand the next request one that already
 * exists.
 */
final class StaffAdvanceReferenceGenerator
{
    private const PREFIX = 'ADV-';

    public function next(): string
    {
        $highest = (int) DB::table('staff_advances')
            ->selectRaw('COALESCE(MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)), 0) AS seq')
            ->value('seq');

        return self::PREFIX.str_pad((string) ($highest + 1), 7, '0', STR_PAD_LEFT);
    }
}
