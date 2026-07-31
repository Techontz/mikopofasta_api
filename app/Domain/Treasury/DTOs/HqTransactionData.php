<?php

declare(strict_types=1);

namespace App\Domain\Treasury\DTOs;

use App\Domain\Treasury\Enums\HqTransactionDirection;

/**
 * Input for raising a headquarters movement.
 * Mirrors the fields the Headquarters Transaction screens collect.
 */
final readonly class HqTransactionData
{
    public function __construct(
        public HqTransactionDirection $direction,
        public string $amount,
        public ?int $fromAccountId,
        public ?int $toAccountId,
        public ?int $branchId,
        public ?string $reason,
        public ?string $requestedOn,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromArray(array $validated): self
    {
        $blankToNull = static function (mixed $v): ?string {
            $s = trim((string) ($v ?? ''));

            return $s === '' ? null : $s;
        };

        $id = static fn (string $key): ?int => isset($validated[$key]) ? (int) $validated[$key] : null;

        return new self(
            direction: HqTransactionDirection::from((string) $validated['direction']),
            // A string all the way to the DECIMAL column.
            amount: (string) $validated['amount'],
            fromAccountId: $id('fromAccountId'),
            toAccountId: $id('toAccountId'),
            branchId: $id('branchId'),
            reason: $blankToNull($validated['reason'] ?? null),
            requestedOn: $blankToNull($validated['requestedOn'] ?? null),
        );
    }
}
