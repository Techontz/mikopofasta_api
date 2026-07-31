<?php

declare(strict_types=1);

namespace App\Domain\Loans\DTOs;

use App\Domain\Loans\Enums\ChargeValueType;

/**
 * Input for the organisation-wide penalty default — Settings → Penalty.
 */
final readonly class PenaltySettingData
{
    public function __construct(
        public ChargeValueType $calculationType,
        public string $amount,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromArray(array $validated): self
    {
        return new self(
            calculationType: ChargeValueType::from((string) $validated['calculationType']),
            amount: (string) $validated['amount'],
        );
    }
}
