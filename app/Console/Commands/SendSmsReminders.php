<?php

namespace App\Console\Commands;

use App\Services\Sms\SmsReminders;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Daily automatic SMS (SMS Centre → Templates): repayment reminders before an instalment is due and overdue reminders
 * after it was missed, worded by each company's templates.
 */
class SendSmsReminders extends Command
{
    protected $signature = 'sms:send-reminders {--date= : Run as of this date (Y-m-d)}';

    protected $description = 'Text customers their repayment and overdue reminders';

    public function handle(SmsReminders $reminders): int
    {
        $date = $this->option('date') ? CarbonImmutable::parse($this->option('date')) : CarbonImmutable::today();
        $summary = $reminders->run($date->startOfDay());

        $this->info(sprintf('Sent %d repayment reminders and %d overdue reminders.', $summary['reminders'], $summary['overdue']));

        return self::SUCCESS;
    }
}
