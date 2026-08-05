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

    /*
     * The zone tier of the client's approval chain — Loan Officer → Branch
     * Manager → Zone Manager → Head Office Credit. Between the branch and the
     * credit decision, which is where the organisation chart already puts it.
     */
    case PendingZoneApproval = 'pending_zone_approval';

    /*
     * Sent back to the originating officer to correct. Deliberately NOT
     * `draft`: an application that was reviewed and returned is a different
     * thing from one never submitted, and collapsing the two would hide every
     * rework loop from the queues and from the officer's own record.
     */
    case ReturnedForModification = 'returned_for_modification';

    /*
     * Paused at whatever stage it had reached, pending something outside the
     * system. `loans.hold_resume_status` remembers where to put it back, so a
     * hold costs the applicant time and not their place in the queue.
     */
    case OnHold = 'on_hold';

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
            self::PendingZoneApproval => 'Pending Zone Approval',
            self::ReturnedForModification => 'Returned for Modification',
            self::OnHold => 'On Hold',
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
            self::Draft, self::PendingManagerApproval, self::PendingZoneApproval,
            self::ReturnedForModification, self::OnHold,
            self::MandatePendingOtp, self::MandateFailed, self::MandateActive,
            self::PendingCreditReview, self::PendingFinance,
            self::AwaitingDisbursement, self::DisbursementFailed, self::Escalated,
        ], true);
    }

    /**
     * Waiting on a human decision in the approval chain.
     *
     * `on_hold` and `returned_for_modification` are excluded deliberately:
     * neither is waiting on an approver. A held loan is waiting on whatever the
     * approver paused it for, and a returned one is waiting on the officer.
     * Counting either as a pending approval would make every approver's queue
     * read longer than the work actually in front of them.
     */
    public function isAwaitingApproval(): bool
    {
        return in_array($this, [
            self::PendingManagerApproval, self::PendingZoneApproval, self::PendingCreditReview,
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
