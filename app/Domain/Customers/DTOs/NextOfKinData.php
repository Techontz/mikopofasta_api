<?php

declare(strict_types=1);

namespace App\Domain\Customers\DTOs;

use App\Domain\Customers\Enums\GuarantorRelationship;

/**
 * Mirrors the frontend's CreateNextOfKinInputSchema.
 */
final readonly class NextOfKinData
{
    public function __construct(
        public string $name,
        public GuarantorRelationship $relationship,
        public string $phone,
        public ?string $address,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) $data['name'],
            relationship: GuarantorRelationship::from((string) $data['relationship']),
            phone: (string) $data['phone'],
            address: ($data['address'] ?? null) === null || $data['address'] === '' ? null : (string) $data['address'],
        );
    }
}
