<?php

declare(strict_types=1);

namespace App\Domain\Auth\Support;

use App\Domain\Auth\Enums\PermissionName as P;
use App\Domain\Auth\Enums\RoleName;

/**
 * Default permission grants per role — backend spec §14, mirroring the
 * frontend's ROLE_PERMISSIONS in config/permissions.ts one-for-one.
 *
 * This is the *seed* default only. Once seeded, the authoritative grants live
 * in the `role_has_permissions` table and are editable through the permission
 * matrix screen, so this class must never be consulted at authorization time —
 * doing so would make the matrix screen cosmetic. It is used by the seeder and
 * by the drift test that proves the seed matches the frontend.
 *
 * Deliberately not granted to any role by default: LOANS_REVIEW_CROSS_BRANCH.
 * Per spec §13/§14 Decision 1, cross-branch loan review is always an explicit
 * additional grant on the individual user, never implied by a role.
 */
final class RolePermissionMatrix
{
    /**
     * @return array<string, list<string>>
     */
    public static function all(): array
    {
        $matrix = [];

        foreach (RoleName::cases() as $role) {
            $matrix[$role->value] = self::for($role);
        }

        return $matrix;
    }

    /**
     * @return list<string>
     */
    public static function for(RoleName $role): array
    {
        $permissions = match ($role) {
            RoleName::SuperAdmin => P::cases(),

            RoleName::Admin => [
                P::CustomersView, P::CustomersManage, P::CustomersApprove,
                P::LoansView, P::LoansCreate, P::LoansApprove,
                P::RepaymentsView, P::RepaymentsManage, P::RepaymentsCashEntry,
                P::LedgerView, P::LedgerReverseRequest,
                P::TreasuryView,
                P::HrView, P::HrManage, P::PayrollGenerate,
                P::ReportsView,
                P::AdminOrgSettings, P::BranchesViewAll, P::UsersManage, P::RolesView,
            ],

            RoleName::Finance => [
                P::CustomersView,
                P::LoansView, P::LoansDisburse,
                P::RepaymentsView, P::RepaymentsManage, P::RepaymentsCashEntry, P::RepaymentsReconcile,
                P::LedgerView, P::LedgerReverseRequest, P::LedgerReverseApprove,
                P::TreasuryView, P::TreasuryManage,
                P::PayrollFinalize,
                P::ReportsView,
                P::BranchesViewAll,
            ],

            RoleName::BranchManager => [
                P::CustomersView, P::CustomersManage, P::CustomersApprove,
                P::LoansView, P::LoansCreate, P::LoansApprove,
                P::RepaymentsView,
                P::ReportsView,
            ],

            RoleName::LoanOfficer => [
                P::CustomersView, P::CustomersManage,
                P::LoansView, P::LoansCreate,
                P::ReportsView,
            ],

            // Telco verification + credit review only — never approval, never creation (§14).
            RoleName::CreditOfficer => [
                P::CustomersView,
                P::LoansView, P::LoansCreditReview,
                P::ReportsView,
            ],

            RoleName::Hr => [
                P::HrView, P::HrManage, P::PayrollGenerate,
                P::ReportsView,
            ],

            RoleName::ZoneManager, RoleName::RegionalManager => [
                P::CustomersView,
                P::LoansView,
                P::RepaymentsView,
                P::ReportsView,
                P::BranchesViewAll,
            ],

            // Cash payment entry only — no reconciliation, no confirmation (§14).
            RoleName::Teller => [
                P::RepaymentsView, P::RepaymentsCashEntry,
            ],

            // Read-only, cross-branch by design: an auditor sees everything
            // financial but holds no manage/approve/finalize/reverse grant.
            RoleName::Auditor => [
                P::CustomersView,
                P::LoansView,
                P::RepaymentsView,
                P::LedgerView,
                P::TreasuryView,
                P::HrView,
                P::ReportsView,
                P::BranchesViewAll,
                P::AuditView,
            ],
        };

        return array_values(array_unique(
            array_map(static fn (P $permission): string => $permission->value, $permissions),
        ));
    }
}
