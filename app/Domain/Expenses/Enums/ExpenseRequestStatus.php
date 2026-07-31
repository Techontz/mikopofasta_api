<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Enums;

/**
 * Where an expense request has got to.
 *
 * Mirrors the frontend's `APPROVAL_STATUSES` in types/operations.ts — the same
 * three the Headquarters Transaction screens use, because they are the same
 * question asked of a different record.
 */
enum ExpenseRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /** Matches the frontend's APPROVAL_LABEL. */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    /**
     * Only a pending request can be decided.
     *
     * Approval posts to the ledger, so re-approving would post the cost twice.
     * The check lives on the enum rather than in the action so every caller —
     * controller, action, test — asks the same question.
     */
    public function isDecidable(): bool
    {
        return $this === self::Pending;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
