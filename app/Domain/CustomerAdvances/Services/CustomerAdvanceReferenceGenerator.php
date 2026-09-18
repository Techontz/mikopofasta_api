<?php

declare(strict_types=1);

namespace App\Domain\CustomerAdvances\Services;

use Illuminate\Support\Facades\DB;

/**
 * CSA-0000001 upward for an advance, CSP-0000001 for a payment against one.
 *
 * Two prefixes rather than one sequence, because the two are printed in
 * different columns on different screens and a shared sequence makes a register
 * look like it has lost rows. Distinct from the staff register's ADV- for the
 * same reason: one reference must name one record.
 *
 * Derived from the highest reference in use rather than from a row count — a
 * soft-deleted advance keeps its reference, and counting would hand the next
 * request one that already exists.
 */
final class CustomerAdvanceReferenceGenerator
{
    private const ADVANCE_PREFIX = 'CSA-';

    private const PAYMENT_PREFIX = 'CSP-';

    public function nextAdvance(): string
    {
        return $this->next('customer_advances', self::ADVANCE_PREFIX);
    }

    public function nextPayment(): string
    {
        return $this->next('customer_advance_payments', self::PAYMENT_PREFIX);
    }

    private function next(string $table, string $prefix): string
    {
        $highest = (int) DB::table($table)
            ->selectRaw('COALESCE(MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)), 0) AS seq')
            ->value('seq');

        return $prefix.str_pad((string) ($highest + 1), 7, '0', STR_PAD_LEFT);
    }
}
