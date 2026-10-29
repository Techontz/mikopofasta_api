<?php

namespace App\Integrations\Linda;

use App\Models\PhoneBondDevice;

/**
 * Phone Bond device service (https://linda.co.tz/, enhancement §5), selected by config('integrations.linda.driver').
 * Only the actions the enhancement needs are declared; no endpoint is assumed until Linda's API documentation is provided.
 */
interface LindaGateway
{
    public function lock(PhoneBondDevice $device, string $reason): LindaResult;

    public function unlock(PhoneBondDevice $device, string $reason): LindaResult;

    /**
     * Tell Linda the device's new debt after a top-up (the enrollment is kept, never reset).
     */
    public function updateDebt(PhoneBondDevice $device, float $amount): LindaResult;
}
