<?php

namespace App\Console\Commands;

use App\Services\PhoneBond;
use Illuminate\Console\Command;

/**
 * eSIMU, every morning in local time: lock the phone when an instalment is still not fully paid after its due date,
 * unlock when the arrears are cleared, mark devices of settled loans Done (still enrolled with Linda).
 */
class EnforcePhoneBond extends Command
{
    protected $signature = 'phone-bond:enforce';

    protected $description = 'Request Linda locks and unlocks for eSIMU devices by repayment status';

    public function handle(PhoneBond $phoneBond): int
    {
        $counts = $phoneBond->enforce(PhoneBond::today());
        $this->info("Lock requests: {$counts['locked']}, unlock requests: {$counts['unlocked']}, devices done: {$counts['done']}.");

        return self::SUCCESS;
    }
}
