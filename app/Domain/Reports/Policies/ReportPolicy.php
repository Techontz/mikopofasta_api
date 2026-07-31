<?php

declare(strict_types=1);

namespace App\Domain\Reports\Policies;

use App\Domain\Auth\Enums\PermissionName;
use App\Models\User;

/**
 * Authorization for reporting — §14.
 *
 * One grant, `reports.view`, held by every role in §14's table. That is
 * deliberate and not laxity: reports are read-only projections, and what a
 * user may SEE in them is decided by branch scope (§13), not by a per-report
 * permission. A Loan Officer and the Finance Director run the same Branch P&L
 * endpoint; the officer's is scoped to their own branch.
 *
 * The frontend's report definitions each carry `permission: REPORTS_VIEW` for
 * the same reason.
 */
final class ReportPolicy
{
    /**
     * Registered as a Gate ability rather than against a model, because a
     * report is not one: it spans loans, payments, payroll and the ledger, and
     * binding it to any single model would imply that model's permission.
     * Pointing it at JournalEntry, for instance, would silently require
     * `ledger.view` and lock out every role §14 grants `reports.view` to.
     */
    public const string VIEW_ABILITY = 'reports.view';

    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::ReportsView);
    }
}
