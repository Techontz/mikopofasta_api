<?php

declare(strict_types=1);

use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\TrialBalanceBuilder;
use App\Domain\Loans\Enums\LoanScheduleStatus;
use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Repayments\Actions\ApplyDueAdvancesAction;
use App\Domain\Repayments\Actions\RunOverdueProcessAction;
use App\Domain\Repayments\Enums\TriggeredBy;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanAdvance;
use App\Support\Money;

/**
 * Customer Advance as a prepaid credit — the client's Decision 1.
 *
 *     Customer pays early
 *       → the money is HELD as a Customer Advance
 *       → the repayment schedule is left completely unchanged
 *       → when each installment reaches its due date the advance is consumed
 *       → only the remaining amount, if any, is asked of the customer
 *
 * The whole point is that a schedule the customer agreed to does not change
 * underneath them, and that money they have already handed over is visible,
 * auditable and spent automatically rather than sitting somewhere inert.
 */
beforeEach(function (): void {
    seedLedgerFoundation();
});

function advanceOf(Loan $loan): Money
{
    return LoanAdvance::balanceFor((int) $loan->getKey());
}

function balanced(): bool
{
    return app(TrialBalanceBuilder::class)->build()['balanced'];
}

describe('money paid early is held, not applied', function (): void {
    it('leaves the schedule completely unchanged', function (): void {
        $loan = activeLoan();

        $before = $loan->schedules->sortBy('installment_number')
            ->map(fn ($s): string => $s->totalDue()->toDecimalString())->values()->all();

        payCash($loan, '75000.00')->assertCreated();

        $after = $loan->fresh(['schedules'])->schedules->sortBy('installment_number')
            ->map(fn ($s): string => $s->totalDue()->toDecimalString())->values()->all();

        // Not one installment moved. That is the promise the client made.
        expect($after)->toBe($before)
            ->and($loan->fresh(['schedules'])->schedules->every(
                fn ($s): bool => $s->status === LoanScheduleStatus::Pending,
            ))->toBeTrue();
    });

    it('holds it as a liability rather than recognising income', function (): void {
        $loan = activeLoan();

        payCash($loan, '75000.00')->assertCreated();

        $tb = collect(app(TrialBalanceBuilder::class)->build()['rows'])->keyBy('code');

        expect(advanceOf($loan)->toDecimalString())->toBe('75000.00')
            ->and((string) $tb[SystemAccountCode::CustomerAdvance->value]['balance'])->toBe('75000.00')
            // Nothing has fallen due, so nothing has been earned.
            ->and((string) $tb[SystemAccountCode::InterestIncome->value]['balance'])->toBe('0.00')
            ->and(balanced())->toBeTrue();
    });

    it('records the credit as a movement carrying its journal entry', function (): void {
        $loan = activeLoan();

        payCash($loan, '40000.00')->assertCreated();

        $movement = LoanAdvance::query()->where('loan_id', $loan->getKey())->sole();

        expect($movement->kind)->toBe(LoanAdvance::KIND_CREDIT)
            ->and($movement->amount)->toBe('40000.00')
            ->and($movement->balance_after)->toBe('40000.00')
            ->and($movement->journal_entry_id)->not->toBeNull()
            ->and($movement->payment_id)->not->toBeNull();
    });

    it('accumulates across several early payments', function (): void {
        $loan = activeLoan();

        payCash($loan, '10000.00')->assertCreated();
        payCash($loan, '15000.00')->assertCreated();
        payCash($loan, '5000.00')->assertCreated();

        expect(advanceOf($loan)->toDecimalString())->toBe('30000.00')
            // A balance summed from its own history, not a column that can drift.
            ->and(LoanAdvance::query()->where('loan_id', $loan->getKey())->count())->toBe(3)
            ->and(balanced())->toBeTrue();
    });
});

describe('the advance is consumed as installments fall due', function (): void {
    it('settles an installment from held credit with no payment at all', function (): void {
        $loan = activeLoan();
        $first = $loan->schedules->sortBy('installment_number')->first();

        payCash($loan, $first->totalDue()->toDecimalString())->assertCreated();

        expect($first->fresh()->status)->toBe(LoanScheduleStatus::Pending);

        $this->travelTo($first->due_date->copy()->startOfDay()->addHours(2));
        app(ApplyDueAdvancesAction::class)->handle();

        expect($first->fresh()->status)->toBe(LoanScheduleStatus::Paid)
            ->and(advanceOf($loan)->toDecimalString())->toBe('0.00')
            ->and(balanced())->toBeTrue();
    });

    it('consumes only what is due, leaving the rest held', function (): void {
        $loan = activeLoan();
        $ordered = $loan->schedules->sortBy('installment_number')->values();

        $three = $ordered[0]->totalDue()->add($ordered[1]->totalDue())->add($ordered[2]->totalDue());
        payCash($loan, $three->toDecimalString())->assertCreated();

        // Only the second installment has matured.
        $this->travelTo($ordered[1]->due_date->copy()->startOfDay()->addHours(2));
        app(ApplyDueAdvancesAction::class)->handle();

        expect($ordered[0]->fresh()->status)->toBe(LoanScheduleStatus::Paid)
            ->and($ordered[1]->fresh()->status)->toBe(LoanScheduleStatus::Paid)
            ->and($ordered[2]->fresh()->status)->toBe(LoanScheduleStatus::Pending)
            ->and(advanceOf($loan)->toDecimalString())->toBe($ordered[2]->totalDue()->toDecimalString());
    });

    it('posts Dr Customer Advance without touching cash a second time', function (): void {
        $loan = activeLoan();
        $first = $loan->schedules->sortBy('installment_number')->first();

        payCash($loan, $first->totalDue()->toDecimalString())->assertCreated();

        $this->travelTo($first->due_date->copy()->startOfDay()->addHours(2));
        app(ApplyDueAdvancesAction::class)->handle();

        $entry = JournalEntry::query()->with('lines')
            ->where('source_type', 'advance_consumption')
            ->where('source_id', $loan->getKey())
            ->sole();

        $debits = $entry->lines->filter(fn ($l): bool => $l->debitAmount()->isPositive());

        /*
         * Exactly one debit, and it is the advance liability falling. The cash
         * reached the books when the borrower paid; debiting it again here
         * would recognise the same shilling twice and make the day's receipts
         * read higher than the day's cash.
         */
        expect($debits)->toHaveCount(1)
            ->and($entry->isBalanced())->toBeTrue()
            ->and(balanced())->toBeTrue();
    });

    it('asks the customer only for the shortfall', function (): void {
        $loan = activeLoan();
        $first = $loan->schedules->sortBy('installment_number')->first();

        // Half the installment, paid early.
        $half = $first->totalDue()->allocate(2)[0];
        payCash($loan, $half->toDecimalString())->assertCreated();

        $this->travelTo($first->due_date->copy()->startOfDay()->addHours(2));
        app(ApplyDueAdvancesAction::class)->handle();

        $shortfall = $first->totalDue()->subtract($half);

        expect($first->fresh()->status)->toBe(LoanScheduleStatus::Partial)
            ->and($first->fresh()->outstandingTotal()->toDecimalString())
            ->toBe($shortfall->toDecimalString())
            ->and(advanceOf($loan)->toDecimalString())->toBe('0.00');
    });

    it('closes the loan when the last installment is settled from credit', function (): void {
        $loan = activeLoan();
        $last = $loan->schedules->sortBy('installment_number')->last();

        payCash($loan, $loan->outstandingTotal()->toDecimalString())->assertCreated();

        expect($loan->fresh()->status)->toBe(LoanStatus::Active);

        $this->travelTo($last->due_date->copy()->startOfDay()->addHours(2));
        app(ApplyDueAdvancesAction::class)->handle();

        expect($loan->fresh()->status)->toBe(LoanStatus::Closed)
            ->and($loan->fresh()->closed_at)->not->toBeNull()
            ->and(advanceOf($loan)->toDecimalString())->toBe('0.00')
            ->and(balanced())->toBeTrue();
    });

    it('audits every consumption with the balance before and after', function (): void {
        $loan = activeLoan();
        $first = $loan->schedules->sortBy('installment_number')->first();

        payCash($loan, $first->totalDue()->add(Money::of('5000.00'))->toDecimalString())->assertCreated();

        $this->travelTo($first->due_date->copy()->startOfDay()->addHours(2));
        app(ApplyDueAdvancesAction::class)->handle();

        $log = AuditLog::query()
            ->where('action', AuditAction::LoanAdvanceConsumed->value)
            ->where('auditable_id', $loan->getKey())
            ->sole();

        expect($log->after_json['consumed'])->toBe($first->totalDue()->toDecimalString())
            ->and($log->after_json['advance_balance'])->toBe('5000.00')
            ->and($log->before_json['advance_balance'])
            ->toBe($first->totalDue()->add(Money::of('5000.00'))->toDecimalString());
    });

    it('does nothing at all when no advance is held', function (): void {
        $loan = matureLoan();

        $run = app(ApplyDueAdvancesAction::class)->handle();

        expect($run->loansSettled)->toBe(0)
            ->and($run->advanceConsumed->toDecimalString())->toBe('0.00')
            ->and($loan->fresh(['schedules'])->outstandingTotal()->isPositive())->toBeTrue();
    });
});

describe('a funded installment is never treated as late', function (): void {
    it('is settled before the penalty job can judge it', function (): void {
        /*
         * Ordering, asserted rather than left to two adjacent lines in an
         * action. An installment the borrower has already funded was never
         * overdue — charging a penalty and reversing it a moment later would
         * put a charge and its cancellation in the customer's statement for
         * something that never happened.
         */
        $loan = activeLoan();
        $first = $loan->schedules->sortBy('installment_number')->first();

        payCash($loan, $first->totalDue()->toDecimalString())->assertCreated();

        // Well past the product's penalty grace, so the job would certainly
        // have charged this installment had the credit not settled it first.
        $this->travelTo($first->due_date->copy()->addDays(6));

        app(RunOverdueProcessAction::class)->handle(TriggeredBy::Cron);

        /*
         * The funded installment is settled and unpenalised. Later installments
         * on this loan HAVE now fallen due and are legitimately overdue — the
         * credit covered one installment, not the schedule — so the claim under
         * test is about this installment, not about the loan's standing.
         */
        expect($first->fresh()->status)->toBe(LoanScheduleStatus::Paid)
            ->and($first->fresh()->penalty_due)->toBe('0.00')
            ->and(balanced())->toBeTrue();
    });

    it('still penalises the part no credit covered', function (): void {
        $loan = activeLoan();
        $first = $loan->schedules->sortBy('installment_number')->first();

        // Only a fraction of what will be owed.
        payCash($loan, '2000.00')->assertCreated();

        // Past the product's penalty grace, so a genuine shortfall is charged.
        $this->travelTo($first->due_date->copy()->addDays(6));

        app(RunOverdueProcessAction::class)->handle(TriggeredBy::Cron);

        expect($first->fresh()->status)->toBe(LoanScheduleStatus::Overdue)
            ->and(Money::of($first->fresh()->penalty_due)->isPositive())->toBeTrue()
            // The 2,000 was still spent on the installment, not left idle.
            ->and(advanceOf($loan)->toDecimalString())->toBe('0.00');
    });
});

describe('conservation', function (): void {
    it('accounts for every shilling across hold and consumption', function (): void {
        $loan = activeLoan();
        $ordered = $loan->schedules->sortBy('installment_number')->values();

        $paid = Money::zero();

        foreach (['12000.00', '8000.00', '20000.00'] as $amount) {
            payCash($loan, $amount)->assertCreated();
            $paid = $paid->add(Money::of($amount));
        }

        $opening = $loan->fresh(['schedules'])->outstandingTotal();

        $this->travelTo($ordered[1]->due_date->copy()->startOfDay()->addHours(2));
        app(ApplyDueAdvancesAction::class)->handle();

        $cleared = $opening->subtract($loan->fresh(['schedules'])->outstandingTotal());

        // Every shilling paid is either cleared debt or credit still held.
        expect($cleared->add(advanceOf($loan))->toDecimalString())->toBe($paid->toDecimalString())
            ->and(balanced())->toBeTrue();
    });

    it('cannot spend more credit than the borrower handed over', function (): void {
        $loan = activeLoan();
        $ordered = $loan->schedules->sortBy('installment_number')->values();

        payCash($loan, '5000.00')->assertCreated();

        $this->travelTo($ordered[2]->due_date->copy()->startOfDay()->addHours(2));
        app(ApplyDueAdvancesAction::class)->handle();

        $consumed = LoanAdvance::query()
            ->where('loan_id', $loan->getKey())
            ->where('kind', LoanAdvance::KIND_CONSUMPTION)
            ->get()
            ->sum(fn ($m): float => (float) $m->amount);

        expect(abs($consumed))->toBeLessThanOrEqual(5000.0)
            ->and(advanceOf($loan)->isNegative())->toBeFalse()
            ->and(balanced())->toBeTrue();
    });
});
