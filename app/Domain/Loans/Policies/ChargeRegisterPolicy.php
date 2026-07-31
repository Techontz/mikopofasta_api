<?php

declare(strict_types=1);

namespace App\Domain\Loans\Policies;

use App\Domain\Auth\Enums\PermissionName;
use App\Models\User;

/**
 * Who may read the penalty and loan-fee registers.
 *
 * The frontend gates all three screens on `loans.view` OR `repayments.view`
 * (config/legacy-nav.ts, both the Penalty and Loan Fee sections), and this
 * matches it exactly rather than inventing a `charges.*` pair the UI would
 * never check.
 *
 * The pairing is not arbitrary. A penalty is a term of the loan and a figure on
 * the schedule, so a loans role has a legitimate reason to read it; it is also
 * money to be collected, so a repayments role does too. Requiring both would
 * shut out each of them from something they are responsible for.
 *
 * Read-only by design. Nothing in this module writes: penalties are accrued by
 * the overdue job, collected by the repayment engine, and fees charged at
 * disbursement. There is no screen — legacy or rebuilt — that edits a charge
 * after the fact, and a policy method for one would imply there is.
 */
final class ChargeRegisterPolicy
{
    public function view(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::LoansView)
            || $actor->hasPermission(PermissionName::RepaymentsView);
    }
}
