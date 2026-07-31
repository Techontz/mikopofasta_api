<?php

declare(strict_types=1);

namespace App\Domain\Customers\Services;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * Generates `customers.customer_number` in the frontend's format: CU-000001
 * (lib/domain/id-generators.ts).
 *
 * The sequence is derived from the highest existing customer NUMBER, not from
 * MAX(id). Those two diverge as soon as the table has an auto-increment gap —
 * a rolled-back transaction, a failed insert — and numbering off the primary
 * key would then skip visibly (CU-000001 followed by CU-000009), which looks
 * like lost customers to anyone reading a report.
 *
 * Soft-deleted rows are included: their numbers are spent, and reissuing one
 * would collide with a record that still exists.
 *
 * Two concurrent registrations can still compute the same value; the UNIQUE
 * index is the backstop. Gapless numbering under concurrency would need a
 * dedicated sequence table, which the specification does not ask for.
 */
final class CustomerNumberGenerator
{
    public const string PREFIX = 'CU-';

    private const int PAD = 6;

    public function next(): string
    {
        $highest = (int) DB::table('customers')
            ->selectRaw('COALESCE(MAX(CAST(SUBSTRING(customer_number, ?) AS UNSIGNED)), 0) AS seq', [strlen(self::PREFIX) + 1])
            ->value('seq');

        return $this->format($highest + 1);
    }

    public function format(int $sequence): string
    {
        return self::PREFIX.str_pad((string) $sequence, self::PAD, '0', STR_PAD_LEFT);
    }
}
