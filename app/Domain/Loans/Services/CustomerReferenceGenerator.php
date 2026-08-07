<?php

declare(strict_types=1);

namespace App\Domain\Loans\Services;

use App\Domain\Loans\Exceptions\LoanApprovalException;
use App\Models\Branch;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * The customer-facing payment reference — client format: `MF-YYYY-BRANCHCODE-000001`.
 *
 * Issued once, when credit approves (D6 / N4). This is what a borrower quotes at
 * a till or types into a mobile-money prompt, which is the whole reason it looks
 * the way it does: a human reads it aloud, so it carries the institution, the
 * year and the branch in plain sight before the digits start.
 *
 * ## Why the sequence is scoped to branch AND year
 *
 * Both are already in the string. A global sequence would make the branch
 * segment decorative — two branches could never share `000001` even though the
 * codes distinguish them — and would leak the institution's total loan volume to
 * anybody holding one reference. Per branch per year, the digits mean what they
 * appear to mean: the Nth reference that branch issued this year.
 *
 * ## Why it reads the highest existing reference, not a counter
 *
 * The same reasoning as every other generator here: numbering off `MAX(id)`, or
 * off a stored counter, drifts from the visible sequence the moment there is a
 * gap — a rolled-back transaction, a deleted row — and a reference that jumps
 * from 000004 to 000009 looks like five missing loans to anybody auditing it.
 *
 * The read and the write must sit inside one transaction: two credit approvals
 * clearing at the same instant would otherwise both read the same highest value.
 * The UNIQUE index on `loans.payment_reference` is the backstop that makes that
 * race a failed write rather than two customers sharing a reference.
 */
final class CustomerReferenceGenerator
{
    public const string PREFIX = 'MF-';

    private const int PAD = 6;

    /**
     * The next reference for a branch, in the given year.
     *
     * @throws LoanApprovalException when the branch has no code
     */
    public function next(Branch $branch, ?int $year = null): string
    {
        $year ??= (int) Date::now()->year;

        $code = trim((string) $branch->code);

        if ($code === '') {
            /*
             * Refused rather than substituted. A placeholder segment would
             * produce a reference that looks quotable, is printed, is read out
             * by a customer — and belongs to no branch. Every branch has a code
             * from the migration onward, so reaching this means somebody
             * cleared it deliberately, and the fix is to give the branch a code
             * rather than to let one loan through without one.
             */
            throw LoanApprovalException::branchHasNoCode($branch->name);
        }

        $prefix = self::prefixFor($code, $year);

        $highest = (int) DB::table('loans')
            ->where('payment_reference', 'like', $prefix.'%')
            ->selectRaw(
                'COALESCE(MAX(CAST(SUBSTRING(payment_reference, ?) AS UNSIGNED)), 0) AS seq',
                [strlen($prefix) + 1],
            )
            ->value('seq');

        return $this->format($highest + 1, $code, $year);
    }

    public function format(int $sequence, string $branchCode, int $year): string
    {
        return self::prefixFor($branchCode, $year).str_pad((string) $sequence, self::PAD, '0', STR_PAD_LEFT);
    }

    /** `MF-2026-HO-` — everything up to the digits. */
    private static function prefixFor(string $branchCode, int $year): string
    {
        return self::PREFIX.$year.'-'.strtoupper($branchCode).'-';
    }
}
