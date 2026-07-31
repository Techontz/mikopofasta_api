<?php

declare(strict_types=1);

namespace App\Domain\Hr\Enums;

/**
 * Mirrors the frontend's STAFF_ADVANCE_STATUSES and `staff_advances.status`
 * (§2.9). The lifecycle is §11's: request → approval (HR) → disbursement
 * (Finance, never HR) → recovered by payroll deduction.
 */
enum StaffAdvanceStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Disbursed = 'disbursed';
    case Recovered = 'recovered';
    case Rejected = 'rejected';

    /**
     * Whether this advance blocks a further request.
     *
     * A rejected or fully recovered advance is finished business; anything
     * else is still in flight, and the frontend refuses a second request while
     * one is.
     */
    public function isInProgress(): bool
    {
        return in_array($this, [self::Requested, self::Approved, self::Disbursed], true);
    }

    /** Money is out the door and is being recovered from payroll. */
    public function isOutstanding(): bool
    {
        return $this === self::Disbursed;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
