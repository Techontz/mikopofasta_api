<?php

declare(strict_types=1);

namespace App\Domain\Organization\DTOs;

use App\Domain\Organization\Enums\BranchType;
use App\Enums\ActiveStatus;

/**
 * Input for creating or updating a branch.
 *
 * Mirrors the frontend's BranchInputSchema in
 * features/admin/organization/branches-actions.ts, which picks
 * name / regionId / zoneId / phone / type / parentBranchId / status.
 *
 * `isHeadOffice` is deliberately absent: the frontend never submits it through
 * this form (it hardcodes `isHeadOffice: false` on create) and moves the flag
 * through the dedicated setHeadOffice action instead, so that promoting a
 * branch always demotes the incumbent in one operation.
 */
final readonly class BranchData
{
    public function __construct(
        public string $name,
        public ?int $regionId,
        public ?int $zoneId,
        public string $phone,
        public BranchType $type,
        public ?int $parentBranchId,
        public ActiveStatus $status,
    ) {}

    /**
     * @param array<string, mixed> $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            name: (string) $validated['name'],
            regionId: self::nullableInt($validated['regionId'] ?? null),
            zoneId: self::nullableInt($validated['zoneId'] ?? null),
            phone: (string) $validated['phone'],
            type: BranchType::from((string) $validated['type']),
            parentBranchId: self::nullableInt($validated['parentBranchId'] ?? null),
            status: ActiveStatus::from((string) ($validated['status'] ?? ActiveStatus::Active->value)),
        );
    }

    private static function nullableInt(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }
}
