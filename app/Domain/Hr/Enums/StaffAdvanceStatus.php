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

    /**
     * The frontend's word for this state.
     *
     * types/salary-advance.ts declares `requested | approved | active | repaid
     * | rejected`; §11 and this enum say `disbursed` and `recovered` for the
     * middle two. Both vocabularies are right for their own side — the backend
     * describes what happened to the money, the screens describe what the
     * employee sees — so they are mapped rather than one being made to give way.
     */
    public function frontendValue(): string
    {
        return match ($this) {
            self::Disbursed => 'active',
            self::Recovered => 'repaid',
            default => $this->value,
        };
    }

    /** The backend state a frontend word refers to. */
    public static function fromFrontend(string $value): self
    {
        return match ($value) {
            'active' => self::Disbursed,
            'repaid' => self::Recovered,
            default => self::from($value),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Approved => 'Approved',
            self::Disbursed => 'Active',
            self::Recovered => 'Repaid',
            self::Rejected => 'Rejected',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
