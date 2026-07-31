<?php

declare(strict_types=1);

namespace App\Domain\Treasury\DTOs;

/**
 * Input for registering or editing a shareholder. Mirrors the frontend's
 * ShareholderInputSchema and the legacy form's five fields.
 */
final readonly class ShareholderData
{
    public function __construct(
        public string $fullName,
        public string $phone,
        public string $email,
        public string $gender,
        public string $dateOfBirth,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromArray(array $validated): self
    {
        return new self(
            fullName: trim((string) $validated['fullName']),
            phone: trim((string) $validated['phone']),
            email: trim((string) $validated['email']),
            gender: (string) $validated['gender'],
            dateOfBirth: (string) $validated['dateOfBirth'],
        );
    }

    /** @return array<string, string> */
    public function toAttributes(): array
    {
        return [
            'full_name' => $this->fullName,
            'phone' => $this->phone,
            'email' => $this->email,
            'gender' => $this->gender,
            'date_of_birth' => $this->dateOfBirth,
        ];
    }
}
