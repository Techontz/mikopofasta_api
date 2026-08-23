<?php

declare(strict_types=1);

namespace App\Domain\Auth\Enums;

/**
 * The 29 permission strings from backend spec §14 (plus addendum A-1).
 *
 * These mirror the frontend's `PERMISSIONS` map in types/auth.ts exactly.
 * The frontend gates navigation, route access and action buttons on these
 * literal strings, so a rename here silently breaks the UI — treat this enum
 * as a published contract, not an implementation detail.
 */
enum PermissionName: string
{
    // Customers
    case CustomersView = 'customers.view';
    case CustomersManage = 'customers.manage';
    case CustomersApprove = 'customers.approve';

    /*
     * Register a customer against an officer other than yourself.
     *
     * Its own grant, following §13/§14 Decision 1's rule that an extra ability
     * is never implied by seniority. The "Employee" field on the registration
     * form is the relationship owner — whose book the customer sits on, and
     * therefore whose portfolio and commission they count towards. An officer
     * filling that in for somebody else is reassigning revenue, so it is a
     * supervisory act, not a data entry one.
     *
     * Without this grant the field is fixed to the signed-in user, which is
     * the correct answer for the officer sitting with the customer.
     */
    case CustomersAssignOfficer = 'customers.assign_officer';

    // Loans
    case LoansView = 'loans.view';
    case LoansCreate = 'loans.create';
    case LoansApprove = 'loans.approve';

    /*
     * The zone tier of the approval chain. Its own grant rather than a second
     * use of `loans.approve`: a Branch Manager holding both would be able to
     * clear their own branch's loan through two consecutive stages, which is
     * the exact escalation the chain exists to prevent.
     */
    case LoansZoneApprove = 'loans.zone_approve';

    /*
     * Pause an application, or send it back to the officer to correct. Held
     * apart from approval because neither decides the loan — a Credit Officer
     * who may return an incomplete file is not thereby a person who may
     * approve one.
     */
    case LoansHold = 'loans.hold';

    /*
     * "Close Loan Early" — client Decision 1, Option B.
     *
     * Its own grant, not `loans.approve` or `loans.disburse`. Settling ends a
     * live loan and cancels its remaining installments; that is a larger act
     * than taking a payment, and it should be possible to give somebody the
     * counter without giving them the power to close the book on a customer.
     *
     * Note what it is NOT: a discount. The interest it forgives is interest for
     * time the borrower is handing back, which the lender would never have
     * earned. See EarlySettlementQuoter.
     */
    case LoansSettleEarly = 'loans.settle_early';

    case LoansCreditReview = 'loans.credit_review';
    case LoansDisburse = 'loans.disburse';
    case LoansReviewCrossBranch = 'loans.review_cross_branch';

    // Repayments
    case RepaymentsView = 'repayments.view';
    case RepaymentsManage = 'repayments.manage';
    case RepaymentsCashEntry = 'repayments.cash_entry';
    case RepaymentsReconcile = 'repayments.reconcile';

    // Ledger
    /*
     * Month-end close, the Reserve fund, and bad debt — Decision Register D1.
     *
     * Reserve is deliberately split across two roles. D1 says "Reserve
     * transfers require Admin approval" and "Reserve belongs to Headquarters /
     * Administration", so Finance may propose a use and only Admin may release
     * it. One permission covering both would make the approval step decorative.
     */
    case AccountingPeriodClose = 'accounting.period_close';
    case ReserveRequest = 'reserve.request';
    case ReserveApprove = 'reserve.approve';
    case LoansWriteOff = 'loans.write_off';
    case LoansRecover = 'loans.recover';

    case LedgerView = 'ledger.view';
    case LedgerReverseRequest = 'ledger.reverse.request';
    case LedgerReverseApprove = 'ledger.reverse.approve';

    // Treasury
    case TreasuryView = 'treasury.view';
    case TreasuryManage = 'treasury.manage';

    // HR & Payroll
    case HrView = 'hr.view';
    case HrManage = 'hr.manage';
    case PayrollGenerate = 'payroll.generate';
    case PayrollFinalize = 'payroll.finalize';

    // Reports
    case ReportsView = 'reports.view';

    // Administration
    case AdminOrgSettings = 'admin.org_settings';
    case BranchesViewAll = 'branches.view_all';
    case UsersManage = 'users.manage';
    case RolesView = 'roles.view';
    case RolesManage = 'roles.manage';

    // Audit
    case AuditView = 'audit.view';

    /**
     * Mirrors the frontend's PERMISSION_LABELS.
     */
    public function label(): string
    {
        return match ($this) {
            self::CustomersView => 'View customers',
            self::CustomersManage => 'Create/edit customers',
            self::CustomersApprove => 'Approve/reject customer registrations',
            self::CustomersAssignOfficer => 'Register a customer for another officer',
            self::LoansView => 'View loans',
            self::LoansCreate => 'Create loan applications',
            self::LoansApprove => 'Approve loans',
            self::LoansZoneApprove => 'Approve loans at zone level',
            self::LoansSettleEarly => 'Settle loans early (waives unearned interest)',
            self::LoansHold => 'Hold or return loan applications',
            self::LoansCreditReview => 'Run credit/telco review',
            self::LoansDisburse => 'Execute disbursement',
            self::LoansReviewCrossBranch => 'Review loans cross-branch',
            self::RepaymentsView => 'View repayments',
            self::RepaymentsManage => 'Confirm/allocate repayments',
            self::RepaymentsCashEntry => 'Record cash payments',
            self::RepaymentsReconcile => 'Bank reconciliation',
            self::AccountingPeriodClose => 'Close accounting period',
            self::ReserveRequest => 'Request reserve utilisation',
            self::ReserveApprove => 'Approve reserve utilisation',
            self::LoansWriteOff => 'Write off loans',
            self::LoansRecover => 'Record loan recoveries',
            self::LedgerView => 'View ledger',
            self::LedgerReverseRequest => 'Request reversal',
            self::LedgerReverseApprove => 'Approve reversal',
            self::TreasuryView => 'View treasury',
            self::TreasuryManage => 'Record capital & dividends',
            self::HrView => 'View HR',
            self::HrManage => 'Manage HR',
            self::PayrollGenerate => 'Generate payroll',
            self::PayrollFinalize => 'Finalize payroll',
            self::ReportsView => 'View reports',
            self::AdminOrgSettings => 'Manage org settings',
            self::BranchesViewAll => 'View all branches',
            self::UsersManage => 'Manage users',
            self::RolesView => 'View roles',
            self::RolesManage => 'Manage permission matrix',
            self::AuditView => 'View audit trail',
        };
    }

    /**
     * The group this permission is displayed under in the permission matrix,
     * mirroring the frontend's PERMISSION_GROUPS.
     */
    public function group(): string
    {
        return match ($this) {
            self::CustomersView, self::CustomersManage, self::CustomersApprove,
            self::CustomersAssignOfficer => 'Customers',
            self::LoansView, self::LoansCreate, self::LoansApprove,
            self::LoansZoneApprove, self::LoansHold, self::LoansSettleEarly,
            self::LoansCreditReview, self::LoansDisburse, self::LoansReviewCrossBranch => 'Loans',
            self::RepaymentsView, self::RepaymentsManage,
            self::RepaymentsCashEntry, self::RepaymentsReconcile => 'Repayments',
            self::LedgerView, self::LedgerReverseRequest, self::LedgerReverseApprove => 'Ledger',

            /*
             * Grouped with Ledger rather than Treasury: the close and the
             * Reserve are both accounting acts on the books, and an
             * administrator setting up a Finance role looks for them where the
             * ledger permissions are.
             */
            self::AccountingPeriodClose, self::ReserveRequest, self::ReserveApprove => 'Accounting & Reserve',

            // With Loans, because both are decisions about one loan and the
            // screens that carry them are the loan screens.
            self::LoansWriteOff, self::LoansRecover => 'Loans',
            self::TreasuryView, self::TreasuryManage => 'Treasury',
            self::HrView, self::HrManage, self::PayrollGenerate, self::PayrollFinalize => 'HR & Payroll',
            self::ReportsView => 'Reports',
            self::AdminOrgSettings, self::BranchesViewAll,
            self::UsersManage, self::RolesView, self::RolesManage => 'Administration',
            self::AuditView => 'Audit',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $permission): string => $permission->value, self::cases());
    }

    /**
     * Group order used by the permission-matrix screen.
     *
     * @return list<string>
     */
    public static function groupOrder(): array
    {
        return [
            'Customers',
            'Loans',
            'Repayments',
            'Ledger',
            'Treasury',
            'HR & Payroll',
            'Reports',
            'Administration',
            'Audit',
        ];
    }
}
