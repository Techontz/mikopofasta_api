<?php

namespace App\Console\Commands;

use App\Services\PhoneBondLocks;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * eSIMU: resend lock / unlock commands Linda has not received yet (same idempotency key), check queued commands, alert
 * staff about failures and commands still waiting for the phone. Webhooks remain the primary confirmation.
 */
class SyncPhoneBondCommands extends Command
{
    protected $signature = 'phone-bond:sync';

    protected $description = 'Resend and check pending eSIMU lock / unlock commands with Linda';

    public function handle(PhoneBondLocks $locks): int
    {
        $counts = $locks->sync(CarbonImmutable::now());
        $this->info("Sent: {$counts['sent']}, confirmed: {$counts['confirmed']}, failed: {$counts['failed']}, alerted: {$counts['alerted']}.");

        return self::SUCCESS;
    }
}
