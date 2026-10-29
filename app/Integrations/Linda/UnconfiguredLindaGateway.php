<?php

namespace App\Integrations\Linda;

use App\Models\PhoneBondDevice;

/**
 * Default driver while Linda's API documentation and credentials are not available: every request is refused, so nothing is
 * ever reported as locked or unlocked without Linda's confirmation. Staff act in the Linda portal and record its reference.
 */
class UnconfiguredLindaGateway implements LindaGateway
{
    public const MESSAGE = 'The Linda integration is not configured (API documentation and credentials are required). Act in the Linda portal and record its reference.';

    public function lock(PhoneBondDevice $device, string $reason): LindaResult
    {
        return new LindaResult(false, false, null, self::MESSAGE);
    }

    public function unlock(PhoneBondDevice $device, string $reason): LindaResult
    {
        return new LindaResult(false, false, null, self::MESSAGE);
    }

    public function updateDebt(PhoneBondDevice $device, float $amount): LindaResult
    {
        return new LindaResult(false, false, null, self::MESSAGE);
    }
}
