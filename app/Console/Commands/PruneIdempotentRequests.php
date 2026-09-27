<?php

namespace App\Console\Commands;

use App\Models\IdempotentRequest;
use Illuminate\Console\Command;

/**
 * Removes stored `Idempotency-Key` requests older than the retention window.
 */
class PruneIdempotentRequests extends Command
{
    protected $signature = 'idempotency:prune {--days=7 : Keep requests newer than this many days}';

    protected $description = 'Delete stored Idempotency-Key requests older than the retention window';

    public function handle(): int
    {
        $deleted = IdempotentRequest::query()->where('created_at', '<', now()->subDays(max(1, (int) $this->option('days'))))->delete();

        $this->info("Deleted {$deleted} idempotent requests.");

        return self::SUCCESS;
    }
}
