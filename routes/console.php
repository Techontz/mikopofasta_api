<?php

declare(strict_types=1);

use App\Console\Commands\ApplyPenaltiesCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
|
| §7's overdue/penalty job. Runs once a day, shortly after midnight, because
| PenaltyCalculator measures days past due against the calendar day — running
| it mid-afternoon would produce the same figures as running it at 00:05, but
| the earlier slot means a borrower's arrears are current before anyone opens
| the collections screen.
|
| It does one thing first: ApplyDueAdvancesAction spends any held Customer
| Advance on the installments that have just fallen due. That is the client's
| prepaid-credit rule, and the ordering is the point — an installment the
| borrower has already funded was never late, so the penalty pass must only ever
| see genuine shortfalls. There is no separate schedule entry for it, because a
| second job could drift out of order with this one.
|
| `withoutOverlapping()` is the requirement that a second run cannot start
| while the first is still going. It takes an atomic cache lock (Redis in
| production, per CACHE_STORE), and the expiry is what releases the lock if the
| process is killed rather than exiting — without it a crashed run would block
| every subsequent one forever.
|
| Repeated runs are safe by construction, not merely by the lock: the penalty
| is topped up to the figure PenaltyCalculator returns rather than added to, so
| a second run on the same day charges only a shortfall. See OSC-4 for the one
| respect in which that is imperfect — the behaviour is deliberate and
| unchanged.
|
| `onOneServer()` guards the other direction: several application servers all
| running the scheduler would otherwise each fire the job.
|
*/
Schedule::command(ApplyPenaltiesCommand::class)
    ->dailyAt('00:05')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(expiresAt: 60)
    ->onOneServer()
    ->runInBackground()
    ->description('Apply penalties to overdue installments (spec §7)');
