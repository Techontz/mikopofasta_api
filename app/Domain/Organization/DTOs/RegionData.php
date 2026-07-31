<?php

declare(strict_types=1);

namespace App\Domain\Organization\DTOs;

/**
 * Input for creating or updating a region. The frontend's RegionInputSchema
 * picks name only — regions carry no other editable attribute (spec §2.2).
 */
final readonly class RegionData
{
    public function __construct(public string $name) {}

    /**
     * @param array<string, mixed> $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(name: (string) $validated['name']);
    }
}
