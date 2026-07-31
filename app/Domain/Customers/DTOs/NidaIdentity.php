<?php

declare(strict_types=1);

namespace App\Domain\Customers\DTOs;

use App\Domain\Customers\Enums\Gender;

/**
 * The identity NIDA returns for a national ID number — the frontend's
 * NidaLookupResult. Spec §9 treats these fields as authoritative: the
 * registration wizard fills them in and the officer cannot edit them.
 */
final readonly class NidaIdentity
{
    public function __construct(
        public string $firstName,
        public ?string $middleName,
        public string $lastName,
        public string $dob,
        public Gender $gender,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'firstName' => $this->firstName,
            'middleName' => $this->middleName,
            'lastName' => $this->lastName,
            'dob' => $this->dob,
            'gender' => $this->gender->value,
        ];
    }
}
