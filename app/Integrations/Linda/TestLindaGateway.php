<?php

namespace App\Integrations\Linda;

use App\Models\PhoneBondDevice;

/**
 * Deterministic test driver: confirms every action, except devices whose IMEI ends in "0", which Linda accepts and
 * confirms later.
 */
class TestLindaGateway implements LindaGateway
{
    public function lock(PhoneBondDevice $device, string $reason): LindaResult
    {
        return $this->answer($device, 'LK');
    }

    public function unlock(PhoneBondDevice $device, string $reason): LindaResult
    {
        return $this->answer($device, 'UL');
    }

    public function updateDebt(PhoneBondDevice $device, float $amount): LindaResult
    {
        return $this->answer($device, 'DB');
    }

    private function answer(PhoneBondDevice $device, string $prefix): LindaResult
    {
        $reference = $prefix.'-'.$device->id.'-'.now()->format('His');

        return str_ends_with((string) $device->imei, '0')
            ? new LindaResult(false, true, $reference, 'Accepted; confirmation follows')
            : new LindaResult(true, true, $reference);
    }
}
