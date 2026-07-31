<?php

declare(strict_types=1);

namespace App\Domain\Auth\DTOs;

/**
 * Input for POST /users, mirroring the frontend's CreateUserSchema in
 * features/admin/users/users-actions.ts.
 */
final readonly class CreateUserData
{
    public function __construct(
        public string $name,
        public string $phone,
        public ?string $email,
        public string $password,
        public string $role,
        public ?int $branchId,
        public ?int $zoneId,
        public ?int $regionId,
    ) {}

    /**
     * @param array<string, mixed> $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            name: (string) $validated['name'],
            phone: (string) $validated['phone'],
            email: self::nullableString($validated['email'] ?? null),
            password: (string) $validated['password'],
            role: (string) $validated['role'],
            branchId: self::nullableInt($validated['branchId'] ?? null),
            zoneId: self::nullableInt($validated['zoneId'] ?? null),
            regionId: self::nullableInt($validated['regionId'] ?? null),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return ($value === null || $value === '') ? null : (string) $value;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }
}
