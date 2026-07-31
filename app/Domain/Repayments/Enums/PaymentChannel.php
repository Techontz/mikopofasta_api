<?php

declare(strict_types=1);

namespace App\Domain\Repayments\Enums;

/** Mirrors the frontend's PAYMENT_CHANNELS and `payments.channel` (§2.6). */
enum PaymentChannel: string
{
    case Api = 'api';
    case MobileMoney = 'mobile_money';
    case Bank = 'bank';
    case Cash = 'cash';

    /**
     * Cash lands in the branch till; every other channel lands in a bank
     * account. Mirrors the frontend's isCashChannel().
     */
    public function isCash(): bool
    {
        return $this === self::Cash;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
