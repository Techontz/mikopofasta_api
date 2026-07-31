<?php

declare(strict_types=1);

namespace App\Domain\Customers\Enums;

/**
 * Mirrors the frontend's CUSTOMER_APPROVAL_STATUSES.
 *
 * Not in backend spec §2.4 — the frontend adds it to express §2.3's
 * `customer_categories.requires_extra_approval`: a customer in a category that
 * demands extra approval registers as `pending` and needs a decision before
 * becoming loan-eligible. Categories without it register as `not_required`,
 * which the UI renders as an em dash rather than a badge.
 */
enum CustomerApprovalStatus: string
{
    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
