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

                /*
                 * Decision Register D1: "Reserve transfers require Admin
                 * approval." Admin approves and does not propose — Finance
                 * raises the request, and an approver who could also raise one
                 * would be approving their own.
                 */
                P::ReserveApprove,
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

                /*
                 * Finance closes the books, proposes uses of the Reserve, and
                 * takes bad debt off the book. It cannot approve its own
                 * reserve request — that grant sits with Admin (D1).
                 */
                P::AccountingPeriodClose,
                P::ReserveRequest,
                P::LoansWriteOff, P::LoansRecover,

                // Closing a settled loan on the book is a money decision.
                P::LoansSettleEarly,
            ],

            RoleName::BranchManager => [
                P::CustomersView, P::CustomersManage, P::CustomersApprove,
                P::LoansView, P::LoansCreate, P::LoansApprove,
                P::RepaymentsView,
                P::ReportsView,

                // Stage one of the approval chain: may clear, and may pause or
                // return an incomplete file rather than having to reject it.
                P::LoansHold,

                // A customer settling at the branch counter should not have to
                // wait on Head Office to close their loan.
                P::LoansSettleEarly,
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

                // The client's four decisions at the credit stage. Hold and
                // return, not approve: clearing credit is still `credit_review`.
                P::LoansHold,
            ],

            RoleName::Hr => [
                P::HrView, P::HrManage, P::PayrollGenerate,
                P::ReportsView,
            ],

            /*
             * Stage two of the approval chain. `loans.zone_approve` is the Zone
             * Manager's alone — a Regional Manager oversees performance and is
             * not in the chain the client specified, so giving both the grant
             * would put a tier in the chain nobody asked for.
             */
            RoleName::ZoneManager => [
                P::CustomersView,
                P::LoansView, P::LoansZoneApprove, P::LoansHold,
                P::RepaymentsView,
                P::ReportsView,
                P::BranchesViewAll,
            ],

            RoleName::RegionalManager => [
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
            /*
             * The Head Office as an operational office.
             *
             * Runs the centre: approves loans like a Branch Manager, sees the
             * whole institution (no zone or region pins it, so BranchScope
             * resolves to every branch), and oversees staff. It does NOT hold
             * the money grants — disbursement is Finance's and the close is the
             * Accountant's, because separation of duties does not stop applying
             * because somebody is senior.
             *
             * Nor does it hold `loans.review_cross_branch`. §13/§14 Decision 1
             * is absolute that cross-branch review is never implied by scope or
             * seniority and is always an explicit per-user grant — seeing every
             * branch and being allowed to ACT on every branch are different
             * things, and this role is exactly the one that would blur them.
             */
            RoleName::HeadOfficeManager => [
                P::CustomersView, P::CustomersManage, P::CustomersApprove,
                P::LoansView, P::LoansCreate, P::LoansApprove, P::LoansHold, P::LoansSettleEarly,
                P::RepaymentsView,
                P::LedgerView,
                P::TreasuryView,
                P::HrView,
                P::ReportsView,
                P::BranchesViewAll,
            ],

            /*
             * The books, and nothing that decides who gets money.
             *
             * Ledger, reconciliation and the period close — the work Finance
             * was carrying alone. No `loans.approve`, no `loans.disburse`: an
             * accountant who could also authorise the payment they then record
             * is the oldest control failure there is.
             */
            RoleName::Accountant => [
                P::CustomersView,
                P::LoansView,
                P::RepaymentsView, P::RepaymentsManage, P::RepaymentsReconcile,
                P::LedgerView, P::LedgerReverseRequest,
                P::TreasuryView,
                P::AccountingPeriodClose,
                P::ReportsView,
            ],

            /*
             * The counter. Takes money in and banks it.
             *
             * Distinct from Teller: a teller records a payment against a loan,
             * a cashier also runs the till and the deposits. Neither may
             * confirm their own cash — `repayments.reconcile` is the
             * Accountant's, so the person holding the cash is never the person
             * who agrees it reached the bank.
             */
            RoleName::Cashier => [
                P::CustomersView,
                P::LoansView,
                P::RepaymentsView, P::RepaymentsCashEntry,
                P::TreasuryView,
            ],

            /*
             * Arrears and bad debt. Records what comes back; cannot forgive
             * what does not — `loans.write_off` stays with Finance, so the
             * officer chasing a debt is not the one who can make it disappear.
             */
            RoleName::RecoveryOfficer => [
                P::CustomersView,
                P::LoansView,
                P::RepaymentsView, P::RepaymentsCashEntry,
                P::LoansRecover,
                P::ReportsView,
            ],

            /*
             * Enquiries and record upkeep. Sees the book and decides nothing on
             * it — no approval, no cash, no ledger.
             */
            RoleName::CustomerCare => [
                P::CustomersView, P::CustomersManage,
                P::LoansView,
                P::RepaymentsView,
            ],

            /*
             * Deliberately empty — client Decision 4. See RoleName::System.
             */
            RoleName::System => [],

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
