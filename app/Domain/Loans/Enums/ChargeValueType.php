<?php

declare(strict_types=1);

namespace App\Domain\Loans\Enums;

/**
 * How a configured charge should be read: as an amount in TZS, or as a
 * percentage of the loan.
 *
 * The legacy Settings screens offer exactly these two — labelled "MONEY VALUE"
 * and "PERCENTAGE VALUE" — for both the loan fee and the penalty default, so
 * one enum serves both rather than two that would drift apart.
 *
 * Distinct from PenaltyType, which additionally encodes *when* a penalty
 * accrues (per day, once, on the overdue balance). This only encodes the unit,
 * which is why the two cannot be merged.
 */
enum ChargeValueType: string
{
    case MoneyValue = 'money_value';
    case PercentageValue = 'percentage_value';

    /**
     * True when the paired amount is TZS rather than a rate — the same
     * distinction PenaltyType::rateIsAmount() draws, and for the same reason:
     * rendering 5,000 TZS as "5000%" is what happens when they are conflated.
     */
    public function isAmount(): bool
    {
        return $this === self::MoneyValue;
    }

    /** The wording the legacy screens use, kept for display parity. */
    public function label(): string
    {
        return match ($this) {
            self::MoneyValue => 'MONEY VALUE',
            self::PercentageValue => 'PERCENTAGE VALUE',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
