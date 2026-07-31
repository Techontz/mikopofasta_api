<?php

declare(strict_types=1);

namespace App\Domain\Organization\DTOs;

/**
 * Input for creating or updating a zone. Mirrors the frontend's
 * ZoneInputSchema, which picks name / zoneManagerId.
 */
final readonly class ZoneData
{
    public function __construct(
        public string $name,
        public ?int $zoneManagerId,
    ) {}

    /**
     * @param array<string, mixed> $validated
     */
    public static function fromArray(array $validated): self
    {
        $manager = $validated['zoneManagerId'] ?? null;

        return new self(
            name: (string) $validated['name'],
            zoneManagerId: ($manager === null || $manager === '') ? null : (int) $manager,
        );
    }
}
