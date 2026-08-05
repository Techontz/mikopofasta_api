<?php

declare(strict_types=1);

namespace App\Domain\Repayments\Services;

use Illuminate\Support\Facades\DB;

/**
 * Generates `payments.payment_reference` in the legacy system's format:
 * PAY-0000001.
 *
 * The frontend used to mint these itself; it no longer does, and this is now
 * the only place they are produced — including the reference a settled loan
 * shows as its Settlement Reference. The format is pinned by
 * tests/Feature/Repayments/RepaymentWorkflowTest.php.
 *
 * Derived from the highest existing reference, for the same reason as the
 * customer and loan generators: numbering off MAX(id) skips visibly the moment
 * the table has an auto-increment gap.
 */
final class PaymentReferenceGenerator
{
    public const string PREFIX = 'PAY-';

    private const int PAD = 7;

    public function next(): string
    {
        $highest = (int) DB::table('payments')
            ->selectRaw('COALESCE(MAX(CAST(SUBSTRING(payment_reference, ?) AS UNSIGNED)), 0) AS seq', [strlen(self::PREFIX) + 1])
            ->value('seq');

        return self::PREFIX.str_pad((string) ($highest + 1), self::PAD, '0', STR_PAD_LEFT);
    }
}
