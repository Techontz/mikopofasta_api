<?php

declare(strict_types=1);

namespace App\Domain\CustomerAdvances\Enums;

/**
 * Where a customer salary advance has got to.
 *
 * The backend words describe what happened to the money — `disbursed`, not
 * "active"; `settled`, not "paid" — and the screens use the customer-facing
 * ones. Both are right for their own side, so they are mapped rather than made
 * to give way, exactly as StaffAdvanceStatus does for the staff register.
 */
enum CustomerAdvanceStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Disbursed = 'disbursed';
    case Settled = 'settled';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /**
     * The word the screens use.
     *
     * "Active" and "Paid" are what the Salary Advance tabs are called, and a
     * table whose badge disagrees with the tab above it reads as a bug.
     */
    public function forFrontend(): string
    {
        return match ($this) {
            self::Disbursed => 'active',
            self::Settled => 'paid',
            default => $this->value,
        };
    }

    /**
     * Accepts either vocabulary, so an API caller using the backend's own words
     * is not turned away by a filter written for the screens.
     */
    public static function fromFrontend(string $value): self
    {
        return match (strtolower(trim($value))) {
            'active' => self::Disbursed,
            'paid', 'repaid' => self::Settled,
            default => self::from(strtolower(trim($value))),
        };
    }

    /** Statuses that still owe money — what "one advance at a time" counts. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Requested, self::Approved, self::Disbursed], true);
    }
}
