<?php

declare(strict_types=1);

namespace App\Domain\Customers\DTOs;

use App\Domain\Customers\Enums\Gender;
use App\Domain\Customers\Enums\GuarantorRelationship;
use App\Domain\Customers\Enums\MaritalStatus;

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
        /* Nullable because the 26 guarantors already on the books have none,
           and inventing one for them would be worse than leaving it blank. */
        public ?Gender $gender = null,
        public ?MaritalStatus $maritalStatus = null,
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
            gender: self::enum(Gender::class, $data['gender'] ?? null),
            maritalStatus: self::enum(MaritalStatus::class, $data['maritalStatus'] ?? null),
        );
    }

    private static function nullable(mixed $value): ?string
    {
        return ($value === null || $value === '') ? null : (string) $value;
    }

    /**
     * `tryFrom`, not `from`: the request has already validated these against
     * the same enum, so an unknown value here means a caller that bypassed
     * validation — and null is the safe reading of it, not a 500.
     *
     * @template T of Gender|MaritalStatus
     *
     * @param class-string<T> $enum
     * @return T|null
     */
    private static function enum(string $enum, mixed $value): Gender|MaritalStatus|null
    {
        $value = self::nullable($value);

        return $value === null ? null : $enum::tryFrom($value);
    }
}
