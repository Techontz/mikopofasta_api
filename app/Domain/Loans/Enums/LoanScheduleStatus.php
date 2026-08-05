<?php

declare(strict_types=1);

namespace App\Domain\Loans\Enums;

/** Mirrors the frontend's LOAN_SCHEDULE_STATUSES and `loan_schedules.status` (§2.5). */
enum LoanScheduleStatus: string
{
    case Pending = 'pending';
    case Partial = 'partial';
    case Paid = 'paid';
    case Overdue = 'overdue';

    /*
     * Cancelled by an early settlement — client Decision 1, Option B.
     *
     * The installment was never billed and never will be: the borrower handed
     * the money back before the period it covered. Distinct from `paid`,
     * because nobody paid it, and distinct from deleting the row, because the
     * schedule the customer agreed to should still be readable afterwards.
     */
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
