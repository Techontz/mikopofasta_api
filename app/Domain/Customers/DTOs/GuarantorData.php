<?php

declare(strict_types=1);

namespace App\Domain\Customers\DTOs;

use App\Domain\Customers\Enums\GuarantorRelationship;

/**
 * Mirrors the frontend's CreateGuarantorInputSchema.
 */
final readonly class GuarantorData
{
    public function __construct(
        public string $name,
        public string $phone,
        public ?string $nidaNumber,
        public GuarantorRelationship $relationship,
        public ?string $address,
        public ?string $occupation,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) $data['name'],
            phone: (string) $data['phone'],
            nidaNumber: self::nullable($data['nidaNumber'] ?? null),
            relationship: GuarantorRelationship::from((string) $data['relationship']),
            address: self::nullable($data['address'] ?? null),
            occupation: self::nullable($data['occupation'] ?? null),
        );
    }

    private static function nullable(mixed $value): ?string
    {
        return ($value === null || $value === '') ? null : (string) $value;
    }
}
