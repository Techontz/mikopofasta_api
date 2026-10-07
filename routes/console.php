<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('loans:process-overdue')->dailyAt('00:30')->withoutOverlapping();
// SMS Centre: reminders go out in the morning, after the night's overdue processing.
Schedule::command('sms:send-reminders')->dailyAt('08:00')->withoutOverlapping();
Schedule::command('idempotency:prune')->dailyAt('01:00')->withoutOverlapping();

// Specification §16/§21: the accounting and commission period closes on the 1st day of the new month.
Schedule::command('mkopa:close-month')->monthlyOn(1, '02:00')->withoutOverlapping();
