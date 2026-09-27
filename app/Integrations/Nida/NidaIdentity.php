<?php

namespace App\Integrations\Nida;

/**
 * Identity record returned by NIDA. NIDA is the source of truth: these values are never edited manually.
 */
final readonly class NidaIdentity
{
    public function __construct(
        public string $nidaNumber,
        public string $firstName,
        public string $middleName,
        public string $lastName,
        public string $dateOfBirth,
        public string $gender,
        public string $phone,
        public ?string $photo = null,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'nida_number' => $this->nidaNumber,
            'first_name' => $this->firstName,
            'middle_name' => $this->middleName,
            'last_name' => $this->lastName,
            'date_of_birth' => $this->dateOfBirth,
            'gender' => $this->gender,
            'phone' => $this->phone,
            'photo' => $this->photo,
        ];
    }

    /**
     * @param  array<string, string|null>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['nida_number'],
            (string) $data['first_name'],
            (string) $data['middle_name'],
            (string) $data['last_name'],
            (string) $data['date_of_birth'],
            (string) $data['gender'],
            (string) $data['phone'],
            $data['photo'] ?? null,
        );
    }
}
