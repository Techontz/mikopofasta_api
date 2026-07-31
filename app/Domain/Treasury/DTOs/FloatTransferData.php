<?php

declare(strict_types=1);

namespace App\Domain\Treasury\DTOs;

use App\Domain\Treasury\Enums\FloatTransferKind;

/**
 * Input for raising a float transfer. One DTO for all three float screens —
 * `kind` says which, and which of the optional ids are populated follows from
 * it, exactly as the form requests enforce.
 */
final readonly class FloatTransferData
{
    public function __construct(
        public FloatTransferKind $kind,
        public string $amount,
        public ?int $fromBranchId,
        public ?int $toBranchId,
        public ?int $fromAccountId,
        public ?int $toAccountId,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromArray(array $validated): self
    {
        $id = static fn (string $key): ?int => isset($validated[$key]) && $validated[$key] !== ''
            ? (int) $validated[$key]
            : null;

        return new self(
            kind: FloatTransferKind::from((string) $validated['kind']),
            amount: (string) $validated['amount'],
            fromBranchId: $id('fromBranchId'),
            toBranchId: $id('toBranchId'),
            fromAccountId: $id('fromAccountId'),
            toAccountId: $id('toAccountId'),
        );
    }
}
