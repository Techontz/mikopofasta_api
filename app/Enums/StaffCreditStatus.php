<?php

namespace App\Enums;

/**
 * Staff loan / staff salary advance lifecycle (spec §29, §30, §32, §49):
 * SUBMITTED → HR APPROVED (ADMIN APPROVED when the request benefits an HR user) → FINANCE APPROVED → DISBURSED (paid from the
 * Fund Account) → REPAYING (first payroll / cash recovery) → COMPLETED, or REJECTED before disbursement.
 */
enum StaffCreditStatus: string
{
    case Submitted = 'submitted';
    case HrApproved = 'hr_approved';
    case AdminApproved = 'admin_approved';
    case FinanceApproved = 'finance_approved';
    case Disbursed = 'disbursed';
    case Repaying = 'repaying';
    case Completed = 'completed';
    case Rejected = 'rejected';

    /**
     * The review stage replaced by an Admin approval for a request benefiting an HR user (§32).
     */
    public const REVIEW_HR = 'hr';

    public const REVIEW_ADMIN = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::HrApproved => 'HR Approved',
            default => ucwords(str_replace('_', ' ', $this->value)),
        };
    }

    /**
     * Reviewed by HR (or Admin) and waiting for Finance approval.
     *
     * @return list<string>
     */
    public static function reviewed(): array
    {
        return [self::HrApproved->value, self::AdminApproved->value];
    }

    /**
     * Still before disbursement (can be rejected).
     *
     * @return list<string>
     */
    public static function awaitingDisbursement(): array
    {
        return [self::Submitted->value, self::HrApproved->value, self::AdminApproved->value, self::FinanceApproved->value];
    }

    /**
     * Money is out and recovery is running (payroll deducts only these).
     *
     * @return list<string>
     */
    public static function recovering(): array
    {
        return [self::Disbursed->value, self::Repaying->value];
    }
}
