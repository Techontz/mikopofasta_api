<?php

namespace App\Enums;

/**
 * Lifecycle of a received payment (Documents: REPAYMENT OVERVIEW).
 */
enum PaymentStatus: string
{
    /** Branch/teller-entered non-cash receipt (mobile money / bank) waiting for Finance approval: nothing is posted yet. */
    case PendingApproval = 'pending_approval';

    /** Teller cash recorded, waiting for bank deposit and Finance verification. */
    case PendingVerification = 'pending_verification';

    /** Teller cash included in a bank deposit slip awaiting reconciliation. */
    case Deposited = 'deposited';

    /** Teller cash verified against the bank and posted to the loan. */
    case Confirmed = 'confirmed';

    /** Teller cash rejected by Finance (entry reversed). */
    case Rejected = 'rejected';

    /** Direct/suspense payment fully posted to a loan. */
    case Allocated = 'allocated';

    /** Unmatched money held in the Suspense account. */
    case Unallocated = 'unallocated';

    /** Suspense money flagged for investigation (fraud suspicion / wrong customer). */
    case Flagged = 'flagged';

    /** Suspense money returned to the payer. */
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::PendingApproval => 'PENDING_APPROVAL',
            self::PendingVerification => 'PENDING_VERIFICATION',
            self::Deposited => 'DEPOSITED',
            self::Confirmed => 'CONFIRMED',
            self::Rejected => 'REJECTED',
            self::Allocated => 'ALLOCATED',
            self::Unallocated => 'UNALLOCATED',
            self::Flagged => 'FLAGGED',
            self::Refunded => 'REFUNDED',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::PendingApproval, self::PendingVerification, self::Deposited => 'warning',
            self::Confirmed, self::Allocated => 'success',
            self::Unallocated => 'info',
            self::Rejected, self::Flagged => 'danger',
            self::Refunded => 'dark',
        };
    }

    /**
     * Statuses shown in the suspense queue.
     *
     * @return list<self>
     */
    public static function suspense(): array
    {
        return [self::Unallocated, self::Flagged];
    }

    /**
     * Branch money received but not yet confirmed by Finance: it changes no loan balance, income or profit (C2/C6).
     *
     * @return list<self>
     */
    public static function awaitingFinance(): array
    {
        return [self::PendingApproval, self::PendingVerification, self::Deposited];
    }

    /**
     * @return list<string>
     */
    public static function values(self ...$statuses): array
    {
        return array_map(fn (self $status): string => $status->value, $statuses);
    }
}
