<?php

declare(strict_types=1);

namespace App\Domain\Accounting\DTOs;

use App\Domain\Accounting\Enums\ReserveUtilisationPurpose;

/**
 * A request to spend Reserve, as submitted — Decision Register D1.
 */
final readonly class ReserveUtilisationData
{
    public function __construct(
        public ReserveUtilisationPurpose $purpose,
        public string $amount,
        public string $narrative,
        public ?int $targetBranchId = null,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromArray(array $validated): self
    {
        return new self(
            purpose: ReserveUtilisationPurpose::from((string) $validated['purpose']),
            amount: (string) $validated['amount'],
            narrative: (string) $validated['narrative'],
            targetBranchId: isset($validated['target_branch_id']) ? (int) $validated['target_branch_id'] : null,
        );
    }
}
