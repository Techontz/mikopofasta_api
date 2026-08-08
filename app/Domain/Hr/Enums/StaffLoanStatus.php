<?php

declare(strict_types=1);

namespace App\Domain\Hr\Enums;

/**
 * Mirrors the frontend's STAFF_LOAN_STATUSES and `staff_loans.status` (§2.9).
 *
 * `active` and `closed` were the only two, and `closed` was assigned nowhere —
 * so a staff loan never finished and payroll deducted against it for ever. The
 * lifecycle states below are §16.7–16.8's, the same three steps a salary
 * advance already walked: requested → HR approves → Finance disburses.
 */
enum StaffLoanStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    /** Disbursed and being recovered. Named `active` because the frontend is. */
    case Active = 'active';
    case Closed = 'closed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Approved => 'Approved',
            self::Active => 'Active',
            self::Closed => 'Closed',
            self::Rejected => 'Rejected',
        };
    }

    /** Money has left the Staff Fund, and payroll recovers against it. */
    public function isOutstanding(): bool
    {
        return $this === self::Active;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
