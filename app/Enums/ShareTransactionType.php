<?php

namespace App\Enums;

/**
 * Kinds of share movement in the share register.
 *
 * Every row moves `shares` from `from_share_holder_id` (null = the company's unissued shares) to
 * `to_share_holder_id` (null = cancelled / returned to unissued). Issuing types therefore increase total issued
 * shares, cancelling types decrease it and a transfer (both sides set) never changes it.
 */
enum ShareTransactionType: string
{
    case InitialAllocation = 'initial_allocation';
    case Issuance = 'issuance';
    case BonusIssuance = 'bonus_issuance';
    case Transfer = 'transfer';
    case Cancellation = 'cancellation';
    case Adjustment = 'adjustment';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::InitialAllocation => 'Initial Allocation',
            self::Issuance => 'Share Issuance',
            self::BonusIssuance => 'Bonus Issuance (non-cash)',
            self::Transfer => 'Share Transfer',
            self::Cancellation => 'Share Cancellation',
            self::Adjustment => 'Share Adjustment',
            self::Reversal => 'Reversal',
        };
    }

    /**
     * Reference prefix, e.g. SHI260913ABCD for an issuance.
     */
    public function prefix(): string
    {
        return match ($this) {
            self::InitialAllocation => 'SHA',
            self::Issuance, self::BonusIssuance => 'SHI',
            self::Transfer => 'SHT',
            self::Cancellation => 'SHC',
            self::Adjustment => 'SHJ',
            self::Reversal => 'SHR',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $type): array => ['value' => $type->value, 'label' => $type->label()], self::cases());
    }
}
