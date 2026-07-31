<?php

declare(strict_types=1);

namespace App\Domain\Customers\DTOs;

/**
 * Bank details captured during registration — the frontend's
 * `bankDetails` object on RegisterCustomerInput.
 */
final readonly class BankDetailsData
{
    public function __construct(
        public string $bankName,
        public string $accountNumber,
        public string $accountName,
        public ?string $phoneNumber = null,
        public ?string $checkNumber = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            bankName: (string) $data['bankName'],
            accountNumber: (string) $data['accountNumber'],
            accountName: (string) $data['accountName'],
            phoneNumber: self::nullable($data['phoneNumber'] ?? null),
            checkNumber: self::nullable($data['checkNumber'] ?? null),
        );
    }

    private static function nullable(mixed $value): ?string
    {
        return ($value === null || $value === '') ? null : (string) $value;
    }
}
