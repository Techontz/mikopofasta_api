<?php

declare(strict_types=1);

namespace App\Domain\Organization\DTOs;

/**
 * Input for updating the singleton company profile. Mirrors the frontend's
 * UpdateCompanyProfileInputSchema in types/organization.ts.
 */
final readonly class CompanyProfileData
{
    public function __construct(
        public string $legalName,
        public string $tradingName,
        public string $registrationNumber,
        public string $tinNumber,
        public string $phone,
        public string $email,
        public string $address,
        public ?int $headquartersBranchId,
    ) {}

    /**
     * @param array<string, mixed> $validated
     */
    public static function fromArray(array $validated): self
    {
        $hq = $validated['headquartersBranchId'] ?? null;

        return new self(
            legalName: (string) $validated['legalName'],
            tradingName: (string) $validated['tradingName'],
            registrationNumber: (string) $validated['registrationNumber'],
            tinNumber: (string) $validated['tinNumber'],
            phone: (string) $validated['phone'],
            email: (string) $validated['email'],
            address: (string) $validated['address'],
            headquartersBranchId: ($hq === null || $hq === '') ? null : (int) $hq,
        );
    }
}
