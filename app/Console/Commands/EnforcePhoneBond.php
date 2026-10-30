<?php

namespace App\Console\Commands;

use App\Services\PhoneBond;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Phone Bond lock / unlock rules (enhancement §5.3), daily after the overdue processing: Done for repaid loans, a lock
 * request when the due instalment is still unpaid after the grace day, an unlock request when nothing is overdue.
 */
class EnforcePhoneBond extends Command
{
    protected $signature = 'phone-bond:enforce';

    protected $description = 'Request Linda locks and unlocks for Phone Bond devices by repayment status';

    public function handle(PhoneBond $phoneBond): int
    {
        $counts = $phoneBond->enforce(CarbonImmutable::today());
        $this->info("Lock requests: {$counts['locked']}, unlock requests: {$counts['unlocked']}, devices done: {$counts['done']}.");

        return self::SUCCESS;
    }
}
