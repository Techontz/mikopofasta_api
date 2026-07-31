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

    // Loans
    case LoansView = 'loans.view';
    case LoansCreate = 'loans.create';
    case LoansApprove = 'loans.approve';
    case LoansCreditReview = 'loans.credit_review';
    case LoansDisburse = 'loans.disburse';
    case LoansReviewCrossBranch = 'loans.review_cross_branch';

    // Repayments
    case RepaymentsView = 'repayments.view';
    case RepaymentsManage = 'repayments.manage';
    case RepaymentsCashEntry = 'repayments.cash_entry';
    case RepaymentsReconcile = 'repayments.reconcile';

    // Ledger
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
            self::LoansView => 'View loans',
            self::LoansCreate => 'Create loan applications',
            self::LoansApprove => 'Approve loans',
            self::LoansCreditReview => 'Run credit/telco review',
            self::LoansDisburse => 'Execute disbursement',
            self::LoansReviewCrossBranch => 'Review loans cross-branch',
            self::RepaymentsView => 'View repayments',
            self::RepaymentsManage => 'Confirm/allocate repayments',
            self::RepaymentsCashEntry => 'Record cash payments',
            self::RepaymentsReconcile => 'Bank reconciliation',
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
            self::CustomersView, self::CustomersManage, self::CustomersApprove => 'Customers',
            self::LoansView, self::LoansCreate, self::LoansApprove,
            self::LoansCreditReview, self::LoansDisburse, self::LoansReviewCrossBranch => 'Loans',
            self::RepaymentsView, self::RepaymentsManage,
            self::RepaymentsCashEntry, self::RepaymentsReconcile => 'Repayments',
            self::LedgerView, self::LedgerReverseRequest, self::LedgerReverseApprove => 'Ledger',
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
