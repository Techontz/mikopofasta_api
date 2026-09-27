<?php

namespace App\Enums;

/**
 * Loan lifecycle (Documents: 🏦 LOAN PROCESS OVERVIEW + handwritten "Steps of building").
 *
 * APPLY → MANAGER → (E-MANDATE IF REQUIRED) → CREDIT (TELCO VERIFY) → FINANCE → VODACOM DISBURSEMENT
 * → ACTIVE → (OVERDUE / DEFAULT) → CLOSED. Failure branches: REJECTED, RETURNED (modify → back to
 * loan officer), MANDATE FAILED, DISBURSEMENT FAILED → ESCALATED → CANCELLED / SUSPENSE / other channel.
 */
enum LoanStatus: string
{
    case PendingManagerApproval = 'pending_manager_approval';
    case Returned = 'returned';
    case MandatePendingOtp = 'mandate_pending_otp';
    case MandateFailed = 'mandate_failed';
    case PendingCreditReview = 'pending_credit_review';
    case PendingFinance = 'pending_finance';
    case AwaitingDisbursement = 'awaiting_disbursement';
    case DisbursementFailed = 'disbursement_failed';
    case Escalated = 'escalated';
    case DisbursementSuspense = 'disbursement_suspense';
    case Active = 'active';
    case Overdue = 'overdue';
    case Default = 'default';
    case Closed = 'closed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case WrittenOff = 'written_off';

    /**
     * Previous status names, kept as aliases for the legacy Blade UI that is being removed.
     *
     * @deprecated Use PendingManagerApproval.
     */
    public const Pending = self::PendingManagerApproval;

    /**
     * Live "DISBURSED" meant approved and waiting for cash-out.
     *
     * @deprecated Use AwaitingDisbursement.
     */
    public const Disbursed = self::AwaitingDisbursement;

    /**
     * @deprecated Use Closed.
     */
    public const Done = self::Closed;

    /**
     * Label as displayed on the live system (with corrected English spelling) where a live equivalent exists.
     */
    public function label(): string
    {
        return match ($this) {
            self::PendingManagerApproval => 'PENDING',
            self::Returned => 'RETURNED',
            self::MandatePendingOtp => 'MANDATE PENDING OTP',
            self::MandateFailed => 'MANDATE FAILED',
            self::PendingCreditReview => 'PENDING CREDIT REVIEW',
            self::PendingFinance => 'APPROVED',
            self::AwaitingDisbursement => 'AWAITING DISBURSEMENT',
            self::DisbursementFailed => 'DISBURSEMENT FAILED',
            self::Escalated => 'ESCALATED',
            self::DisbursementSuspense => 'SUSPENSE',
            self::Active => 'ACTIVE',
            self::Overdue => 'OVERDUE',
            self::Default => 'DEFAULT',
            self::Closed => 'DONE',
            self::Rejected => 'REJECTED',
            self::Cancelled => 'CANCELLED',
            self::WrittenOff => 'WRITE-OFF',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::PendingManagerApproval, self::MandatePendingOtp, self::PendingCreditReview, self::Returned => 'warning',
            self::PendingFinance, self::AwaitingDisbursement, self::DisbursementSuspense => 'info',
            self::Active => 'success',
            self::Closed => 'primary',
            self::Overdue, self::MandateFailed, self::DisbursementFailed, self::Escalated => 'danger',
            self::Default, self::Rejected, self::WrittenOff, self::Cancelled => 'danger',
        };
    }

    /**
     * Before money has left: the application can still be edited by the loan officer.
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::PendingManagerApproval, self::Returned], true);
    }

    /**
     * Loans that accept repayments.
     *
     * @return list<self>
     */
    public static function repayable(): array
    {
        return [self::Active, self::Overdue, self::Default];
    }

    /**
     * Loans whose money reached the customer (live "withdrawn" loans).
     *
     * @return list<self>
     */
    public static function disbursed(): array
    {
        return [self::Active, self::Overdue, self::Default, self::Closed, self::WrittenOff];
    }

    /**
     * Applications in the approval pipeline (not yet disbursed, not ended).
     *
     * @return list<self>
     */
    public static function inPipeline(): array
    {
        return [
            self::PendingManagerApproval, self::Returned, self::MandatePendingOtp, self::MandateFailed,
            self::PendingCreditReview, self::PendingFinance, self::AwaitingDisbursement, self::DisbursementFailed,
            self::Escalated, self::DisbursementSuspense,
        ];
    }

    /**
     * Past branch manager approval and not ended by rejection or cancellation: the loan agreement can be generated,
     * printed for the customer to fill and sign, and the signed copy uploaded.
     *
     * @return list<self>
     */
    public static function agreementAvailable(): array
    {
        return [
            self::MandatePendingOtp, self::MandateFailed, self::PendingCreditReview, self::PendingFinance,
            self::AwaitingDisbursement, self::DisbursementFailed, self::Escalated, self::DisbursementSuspense,
            ...self::disbursed(),
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(self ...$statuses): array
    {
        return array_map(fn (self $status): string => $status->value, $statuses);
    }
}
