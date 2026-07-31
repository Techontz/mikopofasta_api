<?php

declare(strict_types=1);

namespace App\Domain\Hr\DTOs;

/**
 * Input for a salary advance band.
 * Mirrors the frontend's `SalaryAdvanceCategoryInputSchema`, plus the recovery
 * term the backend needs to derive an instalment.
 */
final readonly class SalaryAdvanceCategoryData
{
    public function __construct(
        public string $name,
        public string $interestRate,
        public string $fromAmount,
        public string $toAmount,
        public string $chargeFee,
        public int $recoveryPeriods,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromArray(array $validated): self
    {
        return new self(
            name: trim((string) $validated['name']),
            // Strings all the way to the DECIMAL columns: money and rates never
            // pass through a float on their way to the database.
            interestRate: (string) ($validated['interestRate'] ?? '0'),
            fromAmount: (string) $validated['fromAmount'],
            toAmount: (string) $validated['toAmount'],
            chargeFee: (string) ($validated['chargeFee'] ?? '0'),
            recoveryPeriods: (int) ($validated['recoveryPeriods'] ?? 1),
        );
    }
}
