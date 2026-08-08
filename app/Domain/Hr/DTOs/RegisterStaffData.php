<?php

declare(strict_types=1);

namespace App\Domain\Hr\DTOs;

use App\Domain\Auth\DTOs\CreateUserData;
use App\Domain\Hr\Enums\StaffPaymentMethod;
use App\Support\Money;

/**
 * Input for `POST /staff` — §15.5, "HR registers staff".
 *
 * Carries the user account and the employment terms together, because §11
 * creates them together: "HR registers staff (users + staff_profiles created
 * together)". A staff profile without a login is somebody who cannot work the
 * system, and a user without a profile is somebody the payroll cannot pay.
 */
final readonly class RegisterStaffData
{
    public function __construct(
        public CreateUserData $user,
        public Money $baseSalary,
        public bool $commissionEligible,
        public StaffPaymentMethod $paymentMethod,
        public string $hiredAt,
        public ?string $bankName,
        public ?string $bankAccountNumber,
    ) {}

    /**
     * @param array<string, mixed> $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            user: CreateUserData::fromArray($validated),

            // A decimal string, never a float — see App\Support\Money.
            baseSalary: Money::of((string) $validated['baseSalary']),
            commissionEligible: (bool) ($validated['commissionEligible'] ?? false),
            paymentMethod: StaffPaymentMethod::from((string) ($validated['paymentMethod'] ?? 'bank')),
            hiredAt: (string) $validated['hiredAt'],
            bankName: self::nullableString($validated['bankName'] ?? null),
            bankAccountNumber: self::nullableString($validated['bankAccountNumber'] ?? null),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return ($value === null || $value === '') ? null : (string) $value;
    }
}
