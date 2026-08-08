<?php

declare(strict_types=1);

namespace App\Domain\Hr\Services;

use Illuminate\Support\Facades\DB;

/**
 * SLN-0000001 upward — the reference the Staff Loan screen prints on every row.
 *
 * Derived from the highest reference in use rather than from a row count, the
 * same rule every other generator here follows: counting would hand a new loan
 * a reference that already exists the moment any row is removed.
 *
 * `SLN-` rather than `SL-`, because the migration backfilled the pre-existing
 * seeded rows as `SL-000001` and a generator that could collide with those
 * would be handing out a duplicate on its first call.
 */
final class StaffLoanReferenceGenerator
{
    private const PREFIX = 'SLN-';

    public function next(): string
    {
        $highest = (int) DB::table('staff_loans')
            ->where('reference', 'like', self::PREFIX.'%')
            ->selectRaw('COALESCE(MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)), 0) AS seq')
            ->value('seq');

        return self::PREFIX.str_pad((string) ($highest + 1), 7, '0', STR_PAD_LEFT);
    }
}
