<?php

declare(strict_types=1);

namespace App\Domain\Hr\Enums;

/** Mirrors the frontend's STAFF_LOAN_STATUSES and `staff_loans.status` (§2.9). */
enum StaffLoanStatus: string
{
    case Active = 'active';
    case Closed = 'closed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
