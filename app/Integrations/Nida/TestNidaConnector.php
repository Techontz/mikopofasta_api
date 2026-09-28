<?php

namespace App\Integrations\Nida;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Deterministic fixture driver. Any 20-digit number resolves to the same fictional person every time:
 * date of birth from the first 8 digits (YYYYMMDD, falling back to 1990-01-01), gender from digit 18
 * (odd = male), names picked from fixed lists and phone 2557 + digits 10-17.
 * Numbers starting with "0000" are "not found" so failures can be exercised.
 */
class TestNidaConnector implements NidaConnector
{
    private const MALE = ['JUMA', 'BARAKA', 'HAMISI', 'JOSEPH', 'EMMANUEL', 'SAID', 'PETER', 'ATHUMANI'];

    private const FEMALE = ['ASHA', 'NEEMA', 'REHEMA', 'GRACE', 'ZAWADI', 'MWANAIDI', 'ANNA', 'HADIJA'];

    private const MIDDLE = ['ALLY', 'JOHN', 'MOHAMED', 'PAULO', 'RAMADHANI', 'DAUDI', 'SELEMANI', 'MICHAEL'];

    private const LAST = ['MWAKALINGA', 'KIMARO', 'MASSAWE', 'NDUNGURU', 'MUSHI', 'LYIMO', 'MWITA', 'KAPINGA'];

    public function lookup(string $nidaNumber): ?NidaIdentity
    {
        if (preg_match('/^\d{20}$/', $nidaNumber) !== 1 || str_starts_with($nidaNumber, '0000')) {
            return null;
        }

        $hash = crc32($nidaNumber);
        $male = ((int) $nidaNumber[17]) % 2 === 1;

        return new NidaIdentity(
            nidaNumber: $nidaNumber,
            firstName: ($male ? self::MALE : self::FEMALE)[$hash % 8],
            middleName: self::MIDDLE[intdiv($hash, 8) % 8],
            lastName: self::LAST[intdiv($hash, 64) % 8],
            dateOfBirth: $this->dateOfBirth($nidaNumber),
            gender: $male ? 'male' : 'female',
            phone: '2557'.substr($nidaNumber, 9, 8),
        );
    }

    private function dateOfBirth(string $nidaNumber): string
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Ymd', substr($nidaNumber, 0, 8));
            if ($date !== null && $date->format('Ymd') === substr($nidaNumber, 0, 8) && $date->year >= 1920 && $date->isPast()) {
                return $date->toDateString();
            }
        } catch (Throwable) {
            // Fall through to the default date.
        }

        return '1990-01-01';
    }
}
