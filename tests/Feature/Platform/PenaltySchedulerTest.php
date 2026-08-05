<?php

declare(strict_types=1);

use App\Console\Commands\ApplyPenaltiesCommand;
use App\Domain\Ledger\Services\SystemActor;
use App\Domain\Loans\Enums\LoanScheduleStatus;
use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Repayments\Enums\TriggeredBy;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Models\PenaltyRun;
use App\Support\Money;
use Illuminate\Console\Scheduling\Schedule;

/**
 * B1 — §7's overdue/penalty job, now scheduled.
 *
 * The calculation itself is Phase 6's and is covered there. What is proved
 * here is that the cron reaches it, that a second run cannot overlap the
 * first, and that repeated runs stay safe — the three things a scheduled money
 * job has to get right.
 */
beforeEach(function (): void {
    seedLedgerFoundation();
});

describe('the schedule', function (): void {
    it('registers penalty:apply to run daily', function (): void {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e): bool => str_contains($e->command ?? '', 'penalty:apply'));

        expect($events)->toHaveCount(1)
            ->and($events->first()->expression)->toBe('5 0 * * *');
    });

    it('prevents overlapping executions and pins the job to one server', function (): void {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e): bool => str_contains($e->command ?? '', 'penalty:apply'));

        /*
         * Two runs at once would each read the same overdue schedules and each
         * top up the penalty, so the second would charge again on figures the
         * first had already moved.
         */
        expect($event->withoutOverlapping)->toBeTrue()
            // Several app servers each running the scheduler would otherwise
            // each fire the job.
            ->and($event->onOneServer)->toBeTrue()
            // Without an expiry a killed run would hold the lock forever and
            // block every subsequent one.
            ->and($event->expiresAt)->toBe(60);
    });
});

describe('execution', function (): void {
    it('charges an overdue installment and records the run as cron-triggered', function (): void {
        $loan = activeLoan();
        $loan->schedules->sortBy('installment_number')->first()
            ->update(['due_date' => now()->subDays(30)->toDateString()]);

        $this->artisan(ApplyPenaltiesCommand::class)->assertSuccessful();

        $run = PenaltyRun::query()->sole();

        expect($run->triggered_by)->toBe(TriggeredBy::Cron)
            /*
             * The run names the System account.
             *
             * This assertion used to require null, on the reasoning that the
             * scheduler is not a person and naming one would put an employee
             * against a decision they did not make. That reasoning still holds;
             * what changed is that automated work now has an identity of its
             * own, so the run is attributable without being misattributed.
             *
             * `triggered_by` still records `cron`: the account says WHO, the
             * enum says HOW, and neither substitutes for the other.
             */
            ->and($run->triggered_by_user_id)->toBe(app(SystemActor::class)->resolve()->getKey())
            ->and($run->loans_processed)->toBeGreaterThan(0)
            ->and($loan->fresh()->status)->toBe(LoanStatus::Arrears);
    });

    it('posts nothing to the ledger, and says so', function (): void {
        $loan = activeLoan();
        $loan->schedules->sortBy('installment_number')->first()
            ->update(['due_date' => now()->subDays(30)->toDateString()]);

        $before = JournalEntry::query()->count();

        // OSC-1 is unchanged by this phase: penalty income is recognised on
        // collection, so accrual posts nothing.
        $this->artisan(ApplyPenaltiesCommand::class)
            ->expectsOutputToContain('No ledger entry')
            ->assertSuccessful();

        expect(JournalEntry::query()->count())->toBe($before);
    });

    it('stays safe when the scheduler runs it repeatedly', function (): void {
        $loan = activeLoan();
        $first = $loan->schedules->sortBy('installment_number')->first();
        $first->update(['due_date' => now()->subDays(30)->toDateString()]);

        $this->artisan(ApplyPenaltiesCommand::class)->assertSuccessful();
        $afterOne = Money::of($first->fresh()->penalty_due);

        $this->artisan(ApplyPenaltiesCommand::class)->assertSuccessful();
        $this->artisan(ApplyPenaltiesCommand::class)->assertSuccessful();

        $afterThree = Money::of($first->fresh()->penalty_due);

        /*
         * The penalty is topped up to the computed figure, never added to, so
         * three runs do not charge three penalties. It does creep, because the
         * base includes the accrued penalty — that is OSC-4, documented and
         * deliberately unchanged. What matters here is that it stays far below
         * a second full charge.
         */
        expect($afterThree->lessThan($afterOne->add($afterOne)))->toBeTrue()
            ->and(PenaltyRun::query()->count())->toBe(3)
            ->and($loan->fresh()->status)->toBe(LoanStatus::Arrears);
    });

    it('is a no-op when nothing is overdue', function (): void {
        activeLoan();

        $this->artisan(ApplyPenaltiesCommand::class)->assertSuccessful();

        $run = PenaltyRun::query()->sole();

        expect($run->installments_penalised)->toBe(0)
            ->and($run->total_penalty_applied)->toBe('0.00');
    });

    it('marks the overdue installment and leaves paid ones alone', function (): void {
        $loan = activeLoan();
        $ordered = $loan->schedules->sortBy('installment_number')->values();

        $ordered[0]->update(['due_date' => now()->subDays(30)->toDateString()]);
        $ordered[1]->update([
            'due_date' => now()->subDays(20)->toDateString(),
            'status' => LoanScheduleStatus::Paid,
            'principal_paid' => $ordered[1]->principal_due,
            'interest_paid' => $ordered[1]->interest_due,
        ]);

        $this->artisan(ApplyPenaltiesCommand::class)->assertSuccessful();

        expect($ordered[0]->fresh()->status)->toBe(LoanScheduleStatus::Overdue)
            ->and($ordered[1]->fresh()->status)->toBe(LoanScheduleStatus::Paid)
            ->and($ordered[1]->fresh()->penalty_due)->toBe('0.00');
    });

    it('writes an audit row for the run', function (): void {
        activeLoan();

        $this->artisan(ApplyPenaltiesCommand::class)->assertSuccessful();

        $log = AuditLog::query()->where('action', AuditAction::PenaltyRunExecuted->value)->sole();

        expect($log->after_json['ledger_posting'])->toContain('OSC-1');
    });

    it('reaches the same action the manual endpoint uses', function (): void {
        $loan = activeLoan();
        $loan->schedules->sortBy('installment_number')->first()
            ->update(['due_date' => now()->subDays(30)->toDateString()]);

        // The cron and the Finance button must not be able to diverge.
        $this->artisan(ApplyPenaltiesCommand::class)->assertSuccessful();
        $cronRun = PenaltyRun::query()->latest('id')->sole();

        officerAt('Head Office', App\Domain\Auth\Enums\RoleName::Finance);
        $this->postJson('/api/v1/loans/overdue/process')->assertOk();

        $manualRun = PenaltyRun::query()->latest('id')->firstOrFail();

        expect($cronRun->triggered_by)->toBe(TriggeredBy::Cron)
            ->and($manualRun->triggered_by)->toBe(TriggeredBy::Manual)
            // Same shape of result from the same action.
            ->and($manualRun->loans_processed)->toBe($cronRun->loans_processed);
    });
});
