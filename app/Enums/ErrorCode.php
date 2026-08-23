<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Stable, machine-readable error codes (backend spec §1).
 *
 * The frontend switches on these rather than on message text — see
 * lib/api/errors.ts, whose ERROR_COPY map keys off exactly these strings to
 * choose a human-worded UI treatment. Renaming a case is a breaking change.
 *
 * Only codes reachable in Phase 2 are defined; later phases add their own
 * (CUSTOMER_FROZEN, DUPLICATE_TRANSACTION, …).
 */
enum ErrorCode: string
{
    // Generic (§1)
    case ValidationFailed = 'VALIDATION_FAILED';
    case ResourceNotFound = 'RESOURCE_NOT_FOUND';
    case Forbidden = 'FORBIDDEN';
    case Unauthenticated = 'UNAUTHENTICATED';
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';
    case TooManyRequests = 'TOO_MANY_REQUESTS';
    case ServerError = 'SERVER_ERROR';

    // Authentication
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case AccountSuspended = 'ACCOUNT_SUSPENDED';
    case InvalidResetToken = 'INVALID_RESET_TOKEN';
    case CurrentPasswordIncorrect = 'CURRENT_PASSWORD_INCORRECT';
    case PasswordResetUnavailable = 'PASSWORD_RESET_UNAVAILABLE';

    // Identity & access
    case PhoneAlreadyRegistered = 'PHONE_ALREADY_REGISTERED';
    case EmailAlreadyRegistered = 'EMAIL_ALREADY_REGISTERED';
    case CannotModifyOwnAccount = 'CANNOT_MODIFY_OWN_ACCOUNT';
    case RoleNotEditable = 'ROLE_NOT_EDITABLE';
    case ImmutableRecord = 'IMMUTABLE_RECORD';

    // Organization
    case ResourceInUse = 'RESOURCE_IN_USE';
    case HeadOfficeProtected = 'HEAD_OFFICE_PROTECTED';
    case BranchHierarchyCycle = 'BRANCH_HIERARCHY_CYCLE';

    /**
     * Spec §13: attempting to reach another branch's records. Logged to
     * audit_logs as well, because cross-branch snooping is itself an
     * auditable event.
     */
    case BranchScopeViolation = 'BRANCH_SCOPE_VIOLATION';

    // Customers & KYC
    case CustomerAlreadyRegistered = 'CUSTOMER_ALREADY_REGISTERED';
    case InvalidOtp = 'INVALID_OTP';
    case OtpAttemptsExceeded = 'OTP_ATTEMPTS_EXCEEDED';
    case InvalidCustomerState = 'INVALID_CUSTOMER_STATE';
    case KycIncomplete = 'KYC_INCOMPLETE';

    /**
     * Spec §15.2 uses this when a frozen customer is blocked from a new loan.
     * Defined here because the freeze it refers to is set in Phase 4; the loan
     * engine raises it in Phase 5.
     */
    case CustomerFrozen = 'CUSTOMER_FROZEN';

    // Loans & origination (§15.2)
    case LoanNotEligible = 'LOAN_NOT_ELIGIBLE';
    case CategoryNotEligibleForProduct = 'CATEGORY_NOT_ELIGIBLE_FOR_PRODUCT';
    case ScheduleNotSupportedByProduct = 'SCHEDULE_NOT_SUPPORTED_BY_PRODUCT';
    case GuarantorsRequired = 'GUARANTORS_REQUIRED';
    case InvalidLoanState = 'INVALID_LOAN_STATE';
    case IllegalLoanTransition = 'ILLEGAL_LOAN_TRANSITION';
    case InvalidMandateOtp = 'INVALID_MANDATE_OTP';

    // Repayments, collections & ledger (§15.3, §15.4)
    case DuplicateTransaction = 'DUPLICATE_TRANSACTION';
    case UnbalancedJournalEntry = 'UNBALANCED_JOURNAL_ENTRY';
    case EntryAlreadyReversed = 'ENTRY_ALREADY_REVERSED';
    case ReversalNotPermitted = 'REVERSAL_NOT_PERMITTED';
    case InvalidPaymentState = 'INVALID_PAYMENT_STATE';
    case LoanNotRepayable = 'LOAN_NOT_REPAYABLE';
    case SuspenseAlreadyResolved = 'SUSPENSE_ALREADY_RESOLVED';

    /*
    |--------------------------------------------------------------------------
    | HR, payroll & commission (§11)
    |--------------------------------------------------------------------------
    */
    case PayrollPeriodExists = 'PAYROLL_PERIOD_EXISTS';
    case InvalidPayrollState = 'INVALID_PAYROLL_STATE';
    case PayrollEmpty = 'PAYROLL_EMPTY';
    case InvalidAdvanceState = 'INVALID_ADVANCE_STATE';
    case AdvanceInProgress = 'ADVANCE_IN_PROGRESS';
    case CommissionNotDistributable = 'COMMISSION_NOT_DISTRIBUTABLE';
    case StaffProfileExists = 'STAFF_PROFILE_EXISTS';
    case InvalidStaffLoanState = 'INVALID_STAFF_LOAN_STATE';
    case StaffLoanInProgress = 'STAFF_LOAN_IN_PROGRESS';

    /*
    |--------------------------------------------------------------------------
    | Platform (§1)
    |--------------------------------------------------------------------------
    */
    /*
     * The loan product engine. A product is the single source of truth for
     * pricing, so a product that cannot be priced must fail loudly rather than
     * fall back to an arithmetic nobody chose.
     */
    case UnknownInterestFormula = 'UNKNOWN_INTEREST_FORMULA';
    case UnknownRateBasis = 'UNKNOWN_RATE_BASIS';

    /*
     * The platform has not been initialised — the System account is missing.
     *
     * Deliberately not an INVALID_STATE or a generic server error. This is a
     * DEPLOYMENT fault, and the operator who sees it needs to be told to run
     * the seeders rather than to go looking for a bug in the request.
     */
    case SystemUserNotInitialized = 'SYSTEM_USER_NOT_INITIALIZED';
    case RegistrationRequirementsMissing = 'REGISTRATION_REQUIREMENTS_MISSING';
    case InvalidProductConfiguration = 'INVALID_PRODUCT_CONFIGURATION';
    case InvalidSettlement = 'INVALID_SETTLEMENT';

    /*
     * Month-end close and the Reserve fund — Decision Register D1.
     */
    case PeriodAlreadyClosed = 'PERIOD_ALREADY_CLOSED';
    case PeriodNotEnded = 'PERIOD_NOT_ENDED';
    case PriorPeriodOpen = 'PRIOR_PERIOD_OPEN';
    case PeriodEmpty = 'PERIOD_EMPTY';
    case InsufficientReserve = 'INSUFFICIENT_RESERVE';
    case InvalidReserveUtilisationState = 'INVALID_RESERVE_UTILISATION_STATE';

    /*
     * Write-off and recovery — §5's Write-Off and Recovered Loans accounts.
     */
    case LoanNotWriteOffEligible = 'LOAN_NOT_WRITE_OFF_ELIGIBLE';
    case LoanAlreadyWrittenOff = 'LOAN_ALREADY_WRITTEN_OFF';
    case LoanNotWrittenOff = 'LOAN_NOT_WRITTEN_OFF';

    case InvalidWebhookSignature = 'INVALID_WEBHOOK_SIGNATURE';
    case IdempotencyKeyConflict = 'IDEMPOTENCY_KEY_CONFLICT';
    case IdempotencyKeyRequired = 'IDEMPOTENCY_KEY_REQUIRED';
}
