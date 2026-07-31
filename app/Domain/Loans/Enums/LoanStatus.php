<?php

declare(strict_types=1);

namespace App\Domain\Loans\Enums;

/**
 * The loan lifecycle — backend spec §2.5 and the §10 state machine.
 *
 * Mirrors the frontend's LOAN_STATUSES exactly; the loan table, filters and
 * timeline all render off these literal strings.
 */
enum LoanStatus: string
{
    case Draft = 'draft';
    case PendingManagerApproval = 'pending_manager_approval';
    case Rejected = 'rejected';
    case MandatePendingOtp = 'mandate_pending_otp';
    case MandateFailed = 'mandate_failed';
    case MandateActive = 'mandate_active';
    case PendingCreditReview = 'pending_credit_review';
    case PendingFinance = 'pending_finance';
    case AwaitingDisbursement = 'awaiting_disbursement';
    case DisbursementFailed = 'disbursement_failed';
    case Escalated = 'escalated';
    case Active = 'active';
    case Arrears = 'arrears';
    case Defaulted = 'defaulted';
    case WrittenOff = 'written_off';
    case Recovered = 'recovered';
    case Closed = 'closed';
    case Frozen = 'frozen';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingManagerApproval => 'Pending Manager Approval',
            self::Rejected => 'Rejected',
            self::MandatePendingOtp => 'Mandate — Pending OTP',
            self::MandateFailed => 'Mandate Failed',
            self::MandateActive => 'Mandate Active',
            self::PendingCreditReview => 'Pending Credit Review',
            self::PendingFinance => 'Pending Finance',
            self::AwaitingDisbursement => 'Awaiting Disbursement',
            self::DisbursementFailed => 'Disbursement Failed',
            self::Escalated => 'Escalated',
            self::Active => 'Active',
            self::Arrears => 'In Arrears',
            self::Defaulted => 'Defaulted',
            self::WrittenOff => 'Written Off',
            self::Recovered => 'Recovered',
            self::Closed => 'Closed',
            self::Frozen => 'Frozen',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Statuses a loan can never leave — mirrors the frontend's
     * TERMINAL_STATUSES in loan-eligibility.ts, which decides whether a
     * customer still counts as holding an open loan.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Closed, self::Rejected, self::Cancelled,
            self::WrittenOff, self::Recovered,
        ], true);
    }

    /**
     * Still working toward disbursement — the frontend's ORIGINATION_STATUSES.
     */
    public function isOrigination(): bool
    {
        return in_array($this, [
            self::Draft, self::PendingManagerApproval,
            self::MandatePendingOtp, self::MandateFailed, self::MandateActive,
            self::PendingCreditReview, self::PendingFinance,
            self::AwaitingDisbursement, self::DisbursementFailed, self::Escalated,
        ], true);
    }

    /**
     * Money is out and a live balance exists — the frontend's
     * OPEN_BOOK_STATUSES.
     */
    public function isOpenBook(): bool
    {
        return in_array($this, [self::Active, self::Arrears, self::Defaulted, self::Frozen], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
