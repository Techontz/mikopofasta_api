<?php

namespace App\Console\Commands;

use App\Services\PhoneBond;
use Illuminate\Console\Command;

/**
 * eSIMU daily reconciliation with Linda: each managed device's Linda status, last check-in and confirmed lock state.
 */
class ReconcilePhoneBond extends Command
{
    protected $signature = 'phone-bond:reconcile';

    protected $description = 'Reconcile eSIMU devices with their status at Linda';

    public function handle(PhoneBond $phoneBond): int
    {
        $this->info('Devices reconciled: '.$phoneBond->reconcile().'.');

        return self::SUCCESS;
    }
}
