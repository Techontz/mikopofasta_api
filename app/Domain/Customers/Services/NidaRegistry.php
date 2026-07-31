<?php

declare(strict_types=1);

namespace App\Domain\Customers\Services;

use App\Domain\Customers\DTOs\NidaIdentity;
use App\Domain\Customers\Enums\Gender;

/**
 * Stands in for the real NIDA registry integration.
 *
 * Spec §9 is emphatic that NIDA is the source of truth and identity data is
 * never hand-typed — the registration wizard fills name, date of birth and
 * gender from the lookup and the officer cannot override them. Until the real
 * integration exists, this reproduces the frontend's simulator
 * (lib/domain/nida-simulator.ts) EXACTLY, including its 32-bit hash, so a
 * given NIDA number resolves to the same person on both sides and the demo
 * narrative stays coherent.
 *
 * This class is the seam. Replacing it with an HTTP client against the real
 * registry is the only change needed; nothing else in the codebase knows how
 * an identity is obtained.
 */
final class NidaRegistry
{
    /**
     * The fixed OTP the simulated registry accepts. The real integration
     * dispatches a one-time code by SMS; the frontend surfaces this value in
     * its own demo hint text.
     */
    public const string SIMULATED_OTP = '123456';

    /** @var list<string> */
    private const array FIRST_NAMES_MALE = [
        'Juma', 'Hassan', 'Mussa', 'Salum', 'Ally', 'Rashid', 'Omari', 'Baraka',
    ];

    /** @var list<string> */
    private const array FIRST_NAMES_FEMALE = [
        'Fatuma', 'Zainabu', 'Halima', 'Mariam', 'Neema', 'Rehema', 'Salma', 'Amina',
    ];

    /** @var list<string> */
    private const array LAST_NAMES = [
        'Mwakalinga', 'Kimaro', 'Mushi', 'Kessy', 'Mollel', 'Mbwana', 'Komba', 'Ngowi',
    ];

    /**
     * Resolves a NIDA number to an identity.
     *
     * Deterministic: the same number always returns the same person, which is
     * what makes the flow repeatable and gives OTP verification something
     * stable to check against.
     */
    public function lookup(string $nidaNumber): NidaIdentity
    {
        $hash = $this->hash($nidaNumber);

        $gender = $hash % 2 === 0 ? Gender::Male : Gender::Female;
        $firstNames = $gender === Gender::Male ? self::FIRST_NAMES_MALE : self::FIRST_NAMES_FEMALE;

        $firstName = $firstNames[$hash % count($firstNames)];
        $lastName = self::LAST_NAMES[($hash >> 3) % count(self::LAST_NAMES)];

        $year = 1965 + ($hash % 40);
        $month = 1 + (($hash >> 5) % 12);
        $day = 1 + (($hash >> 8) % 28);

        return new NidaIdentity(
            firstName: $firstName,
            middleName: null,
            lastName: $lastName,
            dob: sprintf('%04d-%02d-%02d', $year, $month, $day),
            gender: $gender,
        );
    }

    public function verifyOtp(string $otp): bool
    {
        return hash_equals(self::SIMULATED_OTP, $otp);
    }

    /**
     * The frontend's hashString(), reproduced exactly.
     *
     * JavaScript's `(hash * 31 + charCode) >>> 0` is 32-bit unsigned
     * arithmetic. PHP integers are 64-bit, so the mask is applied explicitly —
     * without it the two implementations diverge after a few characters and
     * the same NIDA number would resolve to different people on each side.
     */
    private function hash(string $input): int
    {
        $hash = 0;

        foreach (str_split($input) as $character) {
            $hash = ($hash * 31 + ord($character)) & 0xFFFFFFFF;
        }

        return $hash;
    }
}
