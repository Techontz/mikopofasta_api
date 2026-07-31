<?php

declare(strict_types=1);

namespace App\Domain\Auth\DTOs;

/**
 * Credentials for POST /auth/login.
 *
 * The identifier is a PHONE number, not an email — the frontend's login form
 * (features/auth/components/login-form.tsx) posts phone + password, and
 * `users.email` is nullable.
 */
final readonly class LoginData
{
    public function __construct(
        public string $phone,
        public string $password,
        public ?string $deviceName = null,
    ) {}

    /**
     * @param array<string, mixed> $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            phone: (string) $validated['phone'],
            password: (string) $validated['password'],
            deviceName: isset($validated['device_name']) ? (string) $validated['device_name'] : null,
        );
    }
}
