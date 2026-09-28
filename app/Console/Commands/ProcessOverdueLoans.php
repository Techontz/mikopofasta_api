<?php

namespace App\Console\Commands;

use App\Services\LoanService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Daily overdue processing (Documents: "Cron Job POST /loans/overdue/process"): penalties on missed
 * instalments, days past due, ACTIVE ⇄ OVERDUE and DEFAULT after the loan end date.
 */
class ProcessOverdueLoans extends Command
{
    protected $signature = 'loans:process-overdue {--date= : Process as of this date (Y-m-d)}';

    protected $description = 'Apply penalties, days past due and overdue / default statuses to running loans';

    public function handle(LoanService $loans): int
    {
        $date = $this->option('date') ? CarbonImmutable::parse($this->option('date')) : CarbonImmutable::today();
        $summary = $loans->applyPenaltiesAndDefaults($date->startOfDay());

        $this->info(sprintf(
            'Processed %d loans: %d penalties (%s), %d overdue, %d moved to default.',
            $summary['processed'], $summary['penalties'], number_format($summary['penalty_amount']), $summary['overdue'], $summary['defaulted'],
        ));

        return self::SUCCESS;
    }
}
