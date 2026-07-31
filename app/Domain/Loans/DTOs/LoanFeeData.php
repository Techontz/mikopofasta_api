<?php

declare(strict_types=1);

namespace App\Domain\Loans\DTOs;

use App\Domain\Loans\Enums\ChargeValueType;

/**
 * Input for configuring a loan product's fee — Settings → Loan Fee.
 * Mirrors the frontend's LoanFeeInputSchema.
 */
final readonly class LoanFeeData
{
    public function __construct(
        public ChargeValueType $feeType,
        public string $feeAmount,
        public string $insuranceAmount,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromArray(array $validated): self
    {
        return new self(
            feeType: ChargeValueType::from((string) $validated['feeType']),
            // Kept as strings all the way to the column: a fee is money, and
            // money does not go through a float on its way to a DECIMAL.
            feeAmount: (string) $validated['feeAmount'],
            insuranceAmount: (string) ($validated['insuranceAmount'] ?? '0'),
        );
    }
}
