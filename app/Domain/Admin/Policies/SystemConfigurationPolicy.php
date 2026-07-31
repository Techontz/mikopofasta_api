<?php

declare(strict_types=1);

namespace App\Domain\Admin\Policies;

use App\Domain\Auth\Enums\PermissionName;
use App\Models\User;

/**
 * Who may read and change system configuration — Settings → Interest Formulas,
 * Repayment Schedules, Notification Templates, Audit Logs.
 *
 * Reads are open to any authenticated user, writes need `admin.org_settings`.
 * That split is the one ZonePolicy, BranchPolicy and LoanChargePolicy already
 * use, and for the same reason: interest formulas and repayment schedules are
 * reference data half the application needs to render a loan product, while
 * changing them alters what future borrowers are quoted.
 *
 * The audit trail is the exception in both directions — see `viewAudit`.
 */
final class SystemConfigurationPolicy
{
    /**
     * Reference data: formulas, schedules, templates.
     *
     * Deliberately not gated. A loan officer's product picker needs the
     * schedule names, and a screen that could not name the formula a product
     * uses would be less useful for no security gain — none of this is
     * sensitive, and all of it is already implied by the product list.
     */
    public function view(User $actor): bool
    {
        return true;
    }

    public function manage(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::AdminOrgSettings);
    }

    /**
     * The audit trail is different, and is gated on its own permission.
     *
     * It records who did what across every module, including salary figures,
     * customer identity changes and every approval — so it reveals more than
     * any single screen it summarises. `audit.view` exists precisely so that
     * reading it can be granted to an auditor without granting the ability to
     * change settings, and withheld from an administrator who can.
     *
     * `admin.org_settings` also opens it, because the frontend's nav offers it
     * under Settings to that grant (config/nav.ts lists both).
     */
    public function viewAudit(User $actor): bool
    {
        return $actor->hasPermission(PermissionName::AuditView)
            || $actor->hasPermission(PermissionName::AdminOrgSettings);
    }
}
