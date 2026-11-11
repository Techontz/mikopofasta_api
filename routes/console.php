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
// eSIMU (Linda Device Lock), in local time: the evening warning on a due date, the next-morning lock / unlock run, a daily
// reconciliation with Linda, and pending lock / unlock commands resent or checked every five minutes.
$esimuTimezone = (string) config('integrations.linda.timezone', 'Africa/Dar_es_Salaam');
Schedule::command('phone-bond:warn')->dailyAt((string) config('integrations.linda.warning_at', '18:00'))->timezone($esimuTimezone)->withoutOverlapping();
Schedule::command('phone-bond:enforce')->dailyAt((string) config('integrations.linda.lock_at', '08:00'))->timezone($esimuTimezone)->withoutOverlapping();
Schedule::command('phone-bond:reconcile')->dailyAt('05:00')->timezone($esimuTimezone)->withoutOverlapping();
Schedule::command('phone-bond:sync')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('idempotency:prune')->dailyAt('01:00')->withoutOverlapping();

// Specification §16/§21: the accounting and commission period closes on the 1st day of the new month.
Schedule::command('mkopa:close-month')->monthlyOn(1, '02:00')->withoutOverlapping();
