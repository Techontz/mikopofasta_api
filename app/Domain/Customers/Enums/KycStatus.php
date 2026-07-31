<?php

declare(strict_types=1);

namespace App\Domain\Customers\Enums;

/**
 * Mirrors the frontend's KYC_STATUSES and `customers.kyc_status` (spec §2.4).
 *
 * Only a customer whose KYC is `completed` may be attached to a loan
 * application (§9) — the loan engine enforces that in Phase 5.
 */
enum KycStatus: string
{
    case Incomplete = 'incomplete';
    case Completed = 'completed';

    public function isComplete(): bool
    {
        return $this === self::Completed;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
