<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\TrialBalanceBuilder;
use App\Domain\Loans\Enums\LoanScheduleStatus;
use App\Models\JournalEntry;
use App\Models\LoanAdvance;
use App\Models\LoanSchedule;
use App\Support\Money;

/**
 * Accounting verification of the loan engine.
 *
 * The formulas are proved arithmetically in the unit suites. This proves the
 * other half: that every shilling the engine schedules and the allocator
 * distributes reaches the double-entry ledger, balances, and can be traced.
 *
 * Runs against the real API, the real ledger and the real MySQL schema.
 */
beforeEach(function (): void {
    seedLedgerFoundation();
});

/** The trial balance, keyed by account code. */
function trial(): array
{
    return collect(app(TrialBalanceBuilder::class)->build()['rows'])->keyBy('code')->all();
}

function balanceOf(SystemAccountCode $code): Money
{
    return Money::of((string) (trial()[$code->value]['balance'] ?? '0.00'));
}

function isBalanced(): bool
{
    return app(TrialBalanceBuilder::class)->build()['balanced'];
}

/** Everything still owed on a loan, from its schedule. */
function outstanding(App\Models\Loan $loan): array
{
    $loan->refresh()->load('schedules');

    return [
        'principal' => Money::sum($loan->schedules->map(fn (LoanSchedule $s) => $s->outstandingPrincipal()))->toDecimalString(),
        'interest' => Money::sum($loan->schedules->map(fn (LoanSchedule $s) => $s->outstandingInterest()))->toDecimalString(),
        'penalty' => Money::sum($loan->schedules->map(fn (LoanSchedule $s) => $s->outstandingPenalty()))->toDecimalString(),
    ];
}

describe('the schedule reaches the books', function (): void {
    it('disburses exactly the principal the engine scheduled', function (): void {
        $loan = activeLoan();

        $scheduled = Money::sum($loan->schedules->map(fn (LoanSchedule $s) => $s->principalDue()));

        expect($scheduled->toDecimalString())->toBe($loan->principal()->toDecimalString());

        $entry = JournalEntry::query()->with('lines')
            ->where('source_type', 'loan_disbursement')
            ->where('source_id', $loan->getKey())
            ->sole();

        expect($entry->totalDebits()->toDecimalString())->toBe($loan->principal()->toDecimalString())
            ->and($entry->isBalanced())->toBeTrue()
            ->and(isBalanced())->toBeTrue();
    });

    it('generates a schedule whose principal closes at exactly zero', function (): void {
        $loan = activeLoan();

        $balance = $loan->principal();

        foreach ($loan->schedules->sortBy('installment_number') as $s) {
            $balance = $balance->subtract($s->principalDue());
        }

        expect($balance->toDecimalString())->toBe('0.00');
    });
});

describe('every repayment reconciles', function (): void {
    it('posts a balanced entry and leaves the trial balance balanced', function (): void {
        $loan = matureLoan();
        officerAt($loan->branch->name, RoleName::Teller);

        $this->postJson('/api/v1/payments/cash', [
            'loanId' => $loan->getKey(),
            'amount' => '25000.00',
        ])->assertCreated();

        $entry = JournalEntry::query()->with('lines')
            ->where('source_type', 'repayment')->latest('id')->firstOrFail();

        expect($entry->isBalanced())->toBeTrue()
            ->and(isBalanced())->toBeTrue();
    });

    it('clears exactly what is due and holds the rest, losing nothing', function (): void {
        /*
         * A payment larger than what has fallen due.
         *
         * The part that is due clears the debt; the part that is not is held as
         * a Customer Advance. Neither figure is asserted directly — what
         * matters, and what this proves, is that the two together account for
         * the whole payment. Money that vanished between them would be money
         * the borrower paid and nobody has.
         */
        $loan = matureLoan();
        $before = outstanding($loan);
        $due = $loan->schedules->sortBy('installment_number')->first()->outstandingTotal();

        officerAt($loan->branch->name, RoleName::Teller);
        $this->postJson('/api/v1/payments/cash', ['loanId' => $loan->getKey(), 'amount' => '30000.00'])
            ->assertCreated();

        $after = outstanding($loan);

        $cleared = Money::of($before['principal'])->subtract(Money::of($after['principal']))
            ->add(Money::of($before['interest'])->subtract(Money::of($after['interest'])))
            ->add(Money::of($before['penalty'])->subtract(Money::of($after['penalty'])));

        $held = LoanAdvance::balanceFor((int) $loan->getKey());

        expect($cleared->toDecimalString())->toBe($due->toDecimalString())
            ->and($cleared->add($held)->toDecimalString())->toBe('30000.00')
            ->and(isBalanced())->toBeTrue();
    });

    it('loses nothing across a long series of payments', function (): void {
        $loan = matureLoan();
        officerAt($loan->branch->name, RoleName::Teller);

        $opening = outstanding($loan);
        $paid = Money::zero();

        for ($i = 0; $i < 12; $i++) {
            $this->postJson('/api/v1/payments/cash', ['loanId' => $loan->getKey(), 'amount' => '5000.00'])
                ->assertCreated();
            $paid = $paid->add(Money::of('5000.00'));
        }

        $closing = outstanding($loan);
        $advance = LoanAdvance::balanceFor((int) $loan->getKey());

        $cleared = Money::of($opening['principal'])->subtract(Money::of($closing['principal']))
            ->add(Money::of($opening['interest'])->subtract(Money::of($closing['interest'])))
            ->add(Money::of($opening['penalty'])->subtract(Money::of($closing['penalty'])));

        // Every shilling paid either cleared debt or is held as an advance.
        expect($cleared->add($advance)->toDecimalString())->toBe($paid->toDecimalString())
            ->and(isBalanced())->toBeTrue();
    });
});

describe('advance payments', function (): void {
    it('banks an overpayment to the Customer Advance account, not to income', function (): void {
        $loan = fullyDueLoan();
        officerAt($loan->branch->name, RoleName::Teller);

        $total = Money::sum($loan->schedules->map(fn (LoanSchedule $s) => $s->totalDue()));
        $overpay = $total->add(Money::of('50000.00'));

        $advanceBefore = balanceOf(SystemAccountCode::CustomerAdvance);

        $this->postJson('/api/v1/payments/cash', [
            'loanId' => $loan->getKey(),
            'amount' => $overpay->toDecimalString(),
        ])->assertCreated();

        // The surplus is a liability, not revenue: nothing fell due to earn it.
        expect(balanceOf(SystemAccountCode::CustomerAdvance)->subtract($advanceBefore)->toDecimalString())
            ->toBe('50000.00')
            ->and(LoanAdvance::balanceFor((int) $loan->getKey())->toDecimalString())->toBe('50000.00')
            ->and(isBalanced())->toBeTrue();
    });

    it('records the advance movement with a running balance and an audit trail', function (): void {
        $loan = fullyDueLoan();
        officerAt($loan->branch->name, RoleName::Teller);

        $total = Money::sum($loan->schedules->map(fn (LoanSchedule $s) => $s->totalDue()));

        $this->postJson('/api/v1/payments/cash', [
            'loanId' => $loan->getKey(),
            'amount' => $total->add(Money::of('12000.00'))->toDecimalString(),
        ])->assertCreated();

        $movement = LoanAdvance::query()->where('loan_id', $loan->getKey())->sole();

        expect($movement->kind)->toBe(LoanAdvance::KIND_CREDIT)
            ->and($movement->amount)->toBe('12000.00')
            ->and($movement->balance_after)->toBe('12000.00')
            ->and($movement->payment_id)->not->toBeNull()
            ->and($movement->journal_entry_id)->not->toBeNull()
            ->and($movement->created_by)->not->toBeNull();
    });

    it('holds money paid before the due date instead of paying the schedule down', function (): void {
        /*
         * The client's ruling, verified end to end.
         *
         *   Customer pays early → the money is HELD as a Customer Advance
         *   → the repayment schedule is left completely unchanged
         *   → each installment is settled from the credit as it falls due.
         *
         * This test previously asserted the OPPOSITE — that early money paid
         * future installments down directly. That behaviour was reported as a
         * limitation, and the client resolved it: an advance is a prepaid
         * credit, not an early settlement.
         */
        $loan = activeLoan();
        officerAt($loan->branch->name, RoleName::Teller);

        $ordered = $loan->schedules->sortBy('installment_number')->values();
        $firstTwo = $ordered[0]->totalDue()->add($ordered[1]->totalDue());

        $this->postJson('/api/v1/payments/cash', [
            'loanId' => $loan->getKey(),
            'amount' => $firstTwo->toDecimalString(),
        ])->assertCreated();

        expect($ordered[0]->fresh()->status)->toBe(LoanScheduleStatus::Pending)
            ->and($ordered[1]->fresh()->status)->toBe(LoanScheduleStatus::Pending)
            // Held in full, as a liability rather than as income.
            ->and(LoanAdvance::balanceFor((int) $loan->getKey())->toDecimalString())
            ->toBe($firstTwo->toDecimalString())
            ->and(balanceOf(SystemAccountCode::CustomerAdvance)->toDecimalString())
            ->toBe($firstTwo->toDecimalString())
            ->and(isBalanced())->toBeTrue();
    });

    it('consumes the advance as each installment reaches its due date', function (): void {
        /*
         * The other half of the ruling: "when each installment reaches its due
         * date the system automatically consumes the Advance first, and only
         * the remaining amount is requested from the customer."
         *
         * The consumption moves no cash — that happened when the borrower paid
         * — so it posts Dr Customer Advance · Cr income/receivable, and the
         * trial balance must survive it.
         */
        $loan = activeLoan();
        officerAt($loan->branch->name, RoleName::Teller);

        $ordered = $loan->schedules->sortBy('installment_number')->values();
        $firstTwo = $ordered[0]->totalDue()->add($ordered[1]->totalDue());

        $this->postJson('/api/v1/payments/cash', [
            'loanId' => $loan->getKey(),
            'amount' => $firstTwo->toDecimalString(),
        ])->assertCreated();

        // Installment one falls due. Nobody pays anything; the credit is spent.
        $this->travelTo($ordered[0]->due_date->copy()->startOfDay()->addHours(2));

        app(App\Domain\Repayments\Actions\ApplyDueAdvancesAction::class)->handle();

        expect($ordered[0]->fresh()->status)->toBe(LoanScheduleStatus::Paid)
            // The second is not due yet, so its share of the credit is untouched.
            ->and($ordered[1]->fresh()->status)->toBe(LoanScheduleStatus::Pending)
            ->and(LoanAdvance::balanceFor((int) $loan->getKey())->toDecimalString())
            ->toBe($ordered[1]->totalDue()->toDecimalString())
            ->and(isBalanced())->toBeTrue();

        // And the movement is on the record, with the entry that describes it.
        $consumption = LoanAdvance::query()
            ->where('loan_id', $loan->getKey())
            ->where('kind', LoanAdvance::KIND_CONSUMPTION)
            ->sole();

        expect($consumption->journal_entry_id)->not->toBeNull();
    });

    it('never penalises an installment the borrower has already funded', function (): void {
        /*
         * Why the advance pass runs BEFORE the penalty job, asserted rather
         * than left to the ordering of two lines in an action.
         */
        $loan = activeLoan();
        officerAt($loan->branch->name, RoleName::Teller);

        $first = $loan->schedules->sortBy('installment_number')->first();

        $this->postJson('/api/v1/payments/cash', [
            'loanId' => $loan->getKey(),
            'amount' => $first->totalDue()->toDecimalString(),
        ])->assertCreated();

        // A week past the due date, with the credit still sitting there.
        $this->travelTo($first->due_date->copy()->addDays(7));

        app(App\Domain\Repayments\Actions\RunOverdueProcessAction::class)
            ->handle(App\Domain\Repayments\Enums\TriggeredBy::Cron);

        expect($first->fresh()->status)->toBe(LoanScheduleStatus::Paid)
            ->and($first->fresh()->penalty_due)->toBe('0.00')
            ->and(isBalanced())->toBeTrue();
    });
});

describe('bad debt still reconciles under the engine', function (): void {
    it('keeps the books balanced through write-off and recovery', function (): void {
        $loan = activeLoan();
        $loan->update(['status' => App\Domain\Loans\Enums\LoanStatus::Defaulted]);

        officerAt('Head Office', RoleName::Finance);

        $this->postJson("/api/v1/loans/{$loan->id}/write-off", [
            'reason' => 'Engine verification — borrower untraceable after twelve months.',
        ])->assertCreated();

        expect(isBalanced())->toBeTrue();

        $this->postJson("/api/v1/loans/{$loan->id}/recovery", ['amount' => '25000.00'])
            ->assertCreated();

        expect(isBalanced())->toBeTrue()
            ->and(balanceOf(SystemAccountCode::RecoveredLoans)->toDecimalString())->toBe('25000.00');
    });
});

describe('the trial balance survives everything', function (): void {
    it('balances after a full lifecycle: disburse, repay, overpay, penalise', function (): void {
        $loan = matureLoan();
        officerAt($loan->branch->name, RoleName::Teller);

        // A normal repayment.
        $this->postJson('/api/v1/payments/cash', ['loanId' => $loan->getKey(), 'amount' => '20000.00'])
            ->assertCreated();
        expect(isBalanced())->toBeTrue();

        // A penalty applied out of band.
        $first = $loan->fresh(['schedules'])->schedules->sortBy('installment_number')->first();
        $first->update(['penalty_due' => '3000.00', 'status' => LoanScheduleStatus::Overdue]);

        // A payment that must clear the penalty first.
        $this->postJson('/api/v1/payments/cash', ['loanId' => $loan->getKey(), 'amount' => '3000.00'])
            ->assertCreated();

        expect($first->fresh()->penalty_paid)->toBe('3000.00')
            ->and(balanceOf(SystemAccountCode::PenaltyIncome)->toDecimalString())->toBe('3000.00')
            ->and(isBalanced())->toBeTrue();

        // And a large overpayment on top.
        $this->postJson('/api/v1/payments/cash', ['loanId' => $loan->getKey(), 'amount' => '900000.00'])
            ->assertCreated();

        expect(isBalanced())->toBeTrue();
    });
});
