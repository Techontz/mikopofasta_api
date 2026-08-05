<?php

declare(strict_types=1);

namespace App\Domain\Loans\Services;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Generates `loans.loan_number` in the legacy system's format: LN-2026-000001
 * (the §15.2 example "LN-2026-000991").
 *
 * The frontend used to mint these itself; it no longer does, and this is now
 * the only place they are produced. The format is pinned by
 * tests/Feature/Platform/OperationalLoggingTest.php.
 *
 * The sequence restarts each calendar year and is derived from the highest
 * existing number for that year — not from MAX(id), which drifts from the
 * numbering as soon as the table has an auto-increment gap. Same reasoning as
 * CustomerNumberGenerator.
 */
final class LoanNumberGenerator
{
    public const string PREFIX = 'LN-';

    private const int PAD = 6;

    public function next(?int $year = null): string
    {
        $year ??= (int) Date::now()->year;
        $prefix = self::PREFIX.$year.'-';

        $highest = (int) DB::table('loans')
            ->where('loan_number', 'like', $prefix.'%')
            ->selectRaw('COALESCE(MAX(CAST(SUBSTRING(loan_number, ?) AS UNSIGNED)), 0) AS seq', [strlen($prefix) + 1])
            ->value('seq');

        return $this->format($highest + 1, $year);
    }

    public function format(int $sequence, int $year): string
    {
        return self::PREFIX.$year.'-'.str_pad((string) $sequence, self::PAD, '0', STR_PAD_LEFT);
    }
}
