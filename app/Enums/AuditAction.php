<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Audit vocabulary.
 *
 * `audit_logs.action` is a VARCHAR, not a DB ENUM — spec §2.1 calls for an
 * extensible vocabulary, and the frontend's types/audit.ts says the same
 * ("intentionally free-text ... but the common ones are collected here so
 * call sites don't invent slightly different strings for the same event").
 * This enum plays that same role on the backend: a shared vocabulary, not a
 * closed set. Later phases add their own cases.
 *
 * Only the Phase 2 (identity & access) actions are defined here.
 */
enum AuditAction: string
{
    case UserLoggedIn = 'USER_LOGGED_IN';
    case UserLoginFailed = 'USER_LOGIN_FAILED';
    case UserLoggedOut = 'USER_LOGGED_OUT';
    case UserCreated = 'USER_CREATED';
    case UserUpdated = 'USER_UPDATED';
    case UserStatusChanged = 'USER_STATUS_CHANGED';
    case UserDeleted = 'USER_DELETED';
    case PasswordChanged = 'PASSWORD_CHANGED';
    case PasswordResetRequested = 'PASSWORD_RESET_REQUESTED';
    case PasswordReset = 'PASSWORD_RESET';
    case RolePermissionsUpdated = 'ROLE_PERMISSIONS_UPDATED';

    // Organization (Phase 3)
    case BranchCreated = 'BRANCH_CREATED';
    case BranchUpdated = 'BRANCH_UPDATED';
    case BranchDeleted = 'BRANCH_DELETED';
    case HeadOfficeChanged = 'HEAD_OFFICE_CHANGED';
    case ZoneCreated = 'ZONE_CREATED';
    case ZoneUpdated = 'ZONE_UPDATED';
    case ZoneDeleted = 'ZONE_DELETED';
    case RegionCreated = 'REGION_CREATED';
    case RegionUpdated = 'REGION_UPDATED';
    case RegionDeleted = 'REGION_DELETED';
    case CompanyProfileUpdated = 'COMPANY_PROFILE_UPDATED';

    /** Spec §13 — cross-branch snooping is itself auditable. */
    case BranchScopeViolation = 'BRANCH_SCOPE_VIOLATION';

    /*
     * Customers & KYC (Phase 4). These values match the frontend's
     * AUDIT_ACTIONS map in types/audit.ts exactly — the customer profile's
     * timeline renders off these strings.
     */
    case CustomerRegistered = 'CUSTOMER_REGISTERED';
    case CustomerApproved = 'CUSTOMER_APPROVED';
    case CustomerRejected = 'CUSTOMER_REJECTED';
    case CustomerFrozen = 'CUSTOMER_FROZEN';
    case CustomerUnfrozen = 'CUSTOMER_UNFROZEN';
    case CustomerSuspended = 'CUSTOMER_SUSPENDED';
    case CustomerReactivated = 'CUSTOMER_REACTIVATED';

    // Not in the frontend's map; the vocabulary is extensible by design.
    case CustomerUpdated = 'CUSTOMER_UPDATED';
    case CustomerCategoryAssigned = 'CUSTOMER_CATEGORY_ASSIGNED';
    case CustomerKycVerified = 'CUSTOMER_KYC_VERIFIED';
    case CustomerDocumentUploaded = 'CUSTOMER_DOCUMENT_UPLOADED';
    case CustomerDocumentRemoved = 'CUSTOMER_DOCUMENT_REMOVED';
    case CustomerCategoryCreated = 'CUSTOMER_CATEGORY_CREATED';
    case CustomerCategoryUpdated = 'CUSTOMER_CATEGORY_UPDATED';
    case CustomerCategoryDeleted = 'CUSTOMER_CATEGORY_DELETED';

    /*
     * Loans (Phase 5). The first five match the frontend's AUDIT_ACTIONS map
     * in types/audit.ts exactly — the loan timeline renders off these strings.
     */
    case LoanApplied = 'LOAN_APPLIED';
    case LoanApproved = 'LOAN_APPROVED';
    case LoanRejected = 'LOAN_REJECTED';
    case LoanDisbursed = 'LOAN_DISBURSED';
    case DisbursementRetried = 'RETRY_DISBURSEMENT';

    // Beyond the frontend's map; the vocabulary is extensible by design.
    case LoanCancelled = 'LOAN_CANCELLED';
    case LoanMandateVerified = 'LOAN_MANDATE_VERIFIED';
    case LoanMandateFailed = 'LOAN_MANDATE_FAILED';
    case LoanTelcoVerified = 'LOAN_TELCO_VERIFIED';
    case LoanDisbursementPrepared = 'LOAN_DISBURSEMENT_PREPARED';
    case LoanProductCreated = 'LOAN_PRODUCT_CREATED';
    case LoanProductUpdated = 'LOAN_PRODUCT_UPDATED';
    case LoanProductDeleted = 'LOAN_PRODUCT_DELETED';
    case LoanProductEligibilityUpdated = 'LOAN_PRODUCT_ELIGIBILITY_UPDATED';

    /*
     * Repayments & ledger (Phase 6). The first three match the frontend's
     * AUDIT_ACTIONS map exactly.
     */
    case PaymentAllocated = 'PAYMENT_ALLOCATED';
    case PaymentReversed = 'PAYMENT_REVERSED';
    case LedgerEntryReversed = 'LEDGER_ENTRY_REVERSED';

    // Beyond the frontend's map; the vocabulary is extensible by design.
    case PaymentReceived = 'PAYMENT_RECEIVED';
    case PaymentUnmatched = 'PAYMENT_UNMATCHED';
    case PaymentConfirmed = 'PAYMENT_CONFIRMED';
    case SuspenseResolved = 'SUSPENSE_RESOLVED';
    case CashDepositRecorded = 'CASH_DEPOSIT_RECORDED';
    case CashDepositReconciled = 'CASH_DEPOSIT_RECONCILED';
    case PenaltyRunExecuted = 'PENALTY_RUN_EXECUTED';
    case ReversalRequested = 'REVERSAL_REQUESTED';
    case ReversalRejected = 'REVERSAL_REJECTED';
    case LoanClosedByRepayment = 'LOAN_CLOSED_BY_REPAYMENT';

    // HR, payroll and commission (§11).
    case StaffRegistered = 'STAFF_REGISTERED';
    case StaffUpdated = 'STAFF_UPDATED';
    case PayrollGenerated = 'PAYROLL_GENERATED';
    case PayrollFinalized = 'PAYROLL_FINALIZED';
    case PayrollPaid = 'PAYROLL_PAID';
    case StaffAdvanceRequested = 'STAFF_ADVANCE_REQUESTED';
    case StaffAdvanceApproved = 'STAFF_ADVANCE_APPROVED';
    case StaffAdvanceRejected = 'STAFF_ADVANCE_REJECTED';
    case StaffAdvanceDisbursed = 'STAFF_ADVANCE_DISBURSED';
    /* Recovery from payroll: one per instalment, plus a final closing event. */
    case StaffAdvanceRepaid = 'STAFF_ADVANCE_REPAID';
    case StaffAdvanceRecovered = 'STAFF_ADVANCE_RECOVERED';
    case SalaryAdvanceCategoryCreated = 'SALARY_ADVANCE_CATEGORY_CREATED';
    case SalaryAdvanceCategoryUpdated = 'SALARY_ADVANCE_CATEGORY_UPDATED';
    case SalaryAdvanceCategoryDeleted = 'SALARY_ADVANCE_CATEGORY_DELETED';

    /*
     * HR approving the figures — §16.1's "salary haiwezi kubadilishwa baada ya
     * approval" needs a recorded moment for "after approval" to mean anything.
     */
    case PayrollApproved = 'PAYROLL_APPROVED';

    /* Staff loans, on the same lifecycle as advances (§14, §16.7–16.8). */
    case StaffLoanRequested = 'STAFF_LOAN_REQUESTED';
    case StaffLoanApproved = 'STAFF_LOAN_APPROVED';
    case StaffLoanRejected = 'STAFF_LOAN_REJECTED';
    case StaffLoanDisbursed = 'STAFF_LOAN_DISBURSED';
    /* Recovery from payroll: one per instalment, plus a final closing event. */
    case StaffLoanRepaid = 'STAFF_LOAN_REPAID';
    case StaffLoanClosed = 'STAFF_LOAN_CLOSED';

    /*
     * What an employee draws and what is withheld. Both are decisions about
     * somebody's pay, which is the definition of a thing an auditor asks about.
     */
    case StaffAllowanceGranted = 'STAFF_ALLOWANCE_GRANTED';
    case StaffAllowanceUpdated = 'STAFF_ALLOWANCE_UPDATED';
    case StaffAllowanceRevoked = 'STAFF_ALLOWANCE_REVOKED';
    case StaffDeductionRecorded = 'STAFF_DEDUCTION_RECORDED';
    case StaffDeductionCancelled = 'STAFF_DEDUCTION_CANCELLED';

    /*
     * System configuration (§Administration). Changing an interest formula's
     * name or a schedule's frequency alters what future borrowers are quoted,
     * and a notification template is the wording customers actually receive —
     * all three are things an auditor asks "who changed this, and when" about.
     */
    case InterestFormulaUpdated = 'INTEREST_FORMULA_UPDATED';
    case RepaymentScheduleCreated = 'REPAYMENT_SCHEDULE_CREATED';
    case RepaymentScheduleUpdated = 'REPAYMENT_SCHEDULE_UPDATED';
    case RepaymentScheduleDeleted = 'REPAYMENT_SCHEDULE_DELETED';
    case NotificationTemplateCreated = 'NOTIFICATION_TEMPLATE_CREATED';
    case NotificationTemplateUpdated = 'NOTIFICATION_TEMPLATE_UPDATED';
    case NotificationTemplateDeleted = 'NOTIFICATION_TEMPLATE_DELETED';
    case CommissionPoolsGenerated = 'COMMISSION_POOLS_GENERATED';
    case PerformanceRecorded = 'PERFORMANCE_RECORDED';

    /*
     * Customer relations. Removing a guarantor changes loan eligibility — §6
     * requires at least one — so it is a decision worth recording even though
     * the frontend's own audit vocabulary has no entry for it.
     */
    case GuarantorAdded = 'GUARANTOR_ADDED';
    case GuarantorRemoved = 'GUARANTOR_REMOVED';
    case NextOfKinAdded = 'NEXT_OF_KIN_ADDED';
    case NextOfKinRemoved = 'NEXT_OF_KIN_REMOVED';

    /*
     * Loan charges configuration (Settings → Loan Fee / Penalty / Reserve).
     * These change what future borrowers are quoted, so who changed a fee and
     * when is exactly the question an auditor asks first.
     */
    case LoanFeeConfigured = 'LOAN_FEE_CONFIGURED';
    case LoanFeeCleared = 'LOAN_FEE_CLEARED';
    case PenaltySettingCreated = 'PENALTY_SETTING_CREATED';
    case PenaltySettingDeleted = 'PENALTY_SETTING_DELETED';
    case ReserveSettingUpdated = 'RESERVE_SETTING_UPDATED';

    /*
     * Capital (§Capital module). Who holds equity, who put money in, and who
     * moved cash between tills are all questions an auditor asks by name.
     */
    case ShareholderRegistered = 'SHAREHOLDER_REGISTERED';
    case ShareholderUpdated = 'SHAREHOLDER_UPDATED';
    case ShareholderDeleted = 'SHAREHOLDER_DELETED';
    case CapitalRecorded = 'CAPITAL_RECORDED';
    case CapitalDeleted = 'CAPITAL_DELETED';
    case FloatTransferRequested = 'FLOAT_TRANSFER_REQUESTED';
    case FloatTransferApproved = 'FLOAT_TRANSFER_APPROVED';
    case FloatTransferRejected = 'FLOAT_TRANSFER_REJECTED';
    case FloatTransferDeleted = 'FLOAT_TRANSFER_DELETED';

    /*
     * Expenses (§Expenses module). Creating a category mints a ledger account,
     * and approving a request moves cash out of a till — the two events an
     * expense audit is actually about. The comment is recorded too, because it
     * is where the reason for a decision is written and it stays editable.
     */
    case ExpenseCategoryCreated = 'EXPENSE_CATEGORY_CREATED';
    case ExpenseCategoryUpdated = 'EXPENSE_CATEGORY_UPDATED';
    case ExpenseCategoryDeleted = 'EXPENSE_CATEGORY_DELETED';
    case ExpenseRequested = 'EXPENSE_REQUESTED';
    case ExpenseApproved = 'EXPENSE_APPROVED';
    case ExpenseRejected = 'EXPENSE_REJECTED';
    case ExpenseCommented = 'EXPENSE_COMMENTED';
    case ExpenseWithdrawn = 'EXPENSE_WITHDRAWN';

    /*
     * Headquarters transactions. The seven head-office pots are outside the §5
     * ledger, so there is no journal entry recording a movement between them —
     * which makes the audit trail the only record of who moved what.
     */
    case HqTransactionRequested = 'HQ_TRANSACTION_REQUESTED';
    case HqTransactionApproved = 'HQ_TRANSACTION_APPROVED';
    case HqTransactionRejected = 'HQ_TRANSACTION_REJECTED';

    /*
     * Bank (§Bank module). Registering an account mints a chart account and may
     * post an opening balance; closing one takes it out of service. Both are
     * chart-of-accounts changes however they are spelled on the screen.
     */
    case BankAccountRegistered = 'BANK_ACCOUNT_REGISTERED';
    case BankAccountUpdated = 'BANK_ACCOUNT_UPDATED';
    case BankAccountClosed = 'BANK_ACCOUNT_CLOSED';
    case BankTransactionRequested = 'BANK_TRANSACTION_REQUESTED';
    case BankTransactionApproved = 'BANK_TRANSACTION_APPROVED';
    case BankTransactionRejected = 'BANK_TRANSACTION_REJECTED';
    case BankTransferCompleted = 'BANK_TRANSFER_COMPLETED';
}
