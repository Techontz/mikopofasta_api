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
    /* A member of staff editing their own personal details. Distinct from
       USER_UPDATED, which is an administrator changing somebody else's
       record — the two answer different questions in an investigation. */
    case UserProfileUpdated = 'USER_PROFILE_UPDATED';
    /* "Sign out my other devices" — deliberate, and the thing somebody does
       when they think an account is compromised, so it is worth its own row. */
    case UserSessionsRevoked = 'USER_SESSIONS_REVOKED';
    case PasswordResetRequested = 'PASSWORD_RESET_REQUESTED';
    case PasswordReset = 'PASSWORD_RESET';
    case RolePermissionsUpdated = 'ROLE_PERMISSIONS_UPDATED';

    // Organization (Phase 3)
    case BranchCreated = 'BRANCH_CREATED';
    case BranchApprovalRouteChanged = 'BRANCH_APPROVAL_ROUTE_CHANGED';
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
    /* A returned registration corrected and sent back to the approver. Its own
       event because "rejected, then fixed, then approved" is a different
       history from "approved first time", and an auditor asks which. */
    case CustomerRegistrationResubmitted = 'CUSTOMER_REGISTRATION_RESUBMITTED';
    case CustomerFrozen = 'CUSTOMER_FROZEN';
    case CustomerUnfrozen = 'CUSTOMER_UNFROZEN';
    case CustomerSuspended = 'CUSTOMER_SUSPENDED';
    case CustomerReactivated = 'CUSTOMER_REACTIVATED';
    // Not in the frontend's map; the vocabulary is extensible by design.
    case CustomerUpdated = 'CUSTOMER_UPDATED';
    case CustomerCategoryAssigned = 'CUSTOMER_CATEGORY_ASSIGNED';
    case CustomerKycVerified = 'CUSTOMER_KYC_VERIFIED';
    /* A biometric capture is its own event, separate from the KYC checklist it
       may or may not complete: a re-scan that changes nothing about the
       checklist still replaced the photograph the branch identifies somebody
       by, and that is a thing an investigator asks about by name. */
    case CustomerFaceScanned = 'CUSTOMER_FACE_SCANNED';
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
     * The approval chain — Loan Officer → Branch Manager → Zone Manager → Head
     * Office Credit. Every decision an approver can take has its own action, so
     * "who held this loan for three weeks" is a query rather than an
     * archaeology exercise through free-text reasons.
     */
    case LoanApprovalStageCleared = 'LOAN_APPROVAL_STAGE_CLEARED';
    case LoanReturnedForModification = 'LOAN_RETURNED_FOR_MODIFICATION';
    case LoanResubmitted = 'LOAN_RESUBMITTED';
    case LoanHeld = 'LOAN_HELD';
    case LoanReleasedFromHold = 'LOAN_RELEASED_FROM_HOLD';

    /** A held Customer Advance spent on an installment that has fallen due. */
    case LoanAdvanceConsumed = 'LOAN_ADVANCE_CONSUMED';

    /**
     * "Close Loan Early" — the deliberate settlement, not a payment that
     * happened to clear the balance. Its own action because the two have
     * different consequences for the customer and only one of them cancels
     * installments.
     */
    case LoanSettledEarly = 'LOAN_SETTLED_EARLY';

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
     * Month-end close and the Reserve fund — Decision Register D1.
     *
     * D1 requires that "every reserve movement must be fully audited". Both
     * directions are here: the close appropriates, and Admin approves what is
     * spent. A reserve that only ever recorded its growth would satisfy the
     * letter of the rule and none of its purpose.
     */
    case PeriodClosed = 'PERIOD_CLOSED';
    case ReserveUtilisationRequested = 'RESERVE_UTILISATION_REQUESTED';
    case ReserveUtilisationApproved = 'RESERVE_UTILISATION_APPROVED';
    case ReserveUtilisationRejected = 'RESERVE_UTILISATION_REJECTED';

    /*
     * §5's Write-Off and Recovered Loans accounts.
     */
    case LoanWrittenOff = 'LOAN_WRITTEN_OFF';
    case LoanRecoveryRecorded = 'LOAN_RECOVERY_RECORDED';

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
