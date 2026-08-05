<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Ledger\Services\TrialBalanceBuilder;
use App\Domain\Loans\Enums\LoanScheduleStatus;
use App\Domain\Loans\Enums\LoanStatus;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\LoanAdvance;
use App\Models\LoanSchedule;
use App\Support\Money;

/**
 * "Close Loan Early" — client Decision 1, Option B.
 *
 * The deliberate counterpart to Option A. Paying the balance early holds the
 * money as an advance and leaves the schedule standing; settling is an act an
 * officer takes on purpose, and only it cancels installments.
 *
 * The rebate rule under test: the borrower repays the principal, pays interest
 * for the time they actually had the money, and is forgiven interest on the
 * time they are handing back.
 */
beforeEach(function (): void {
    seedLedgerFoundation();
});

function settlementQuote(Loan $loan): array
{
    return test()->getJson("/api/v1/loans/{$loan->id}/early-settlement")->assertOk()->json('data');
}

function balancedBooks(): bool
{
    return app(TrialBalanceBuilder::class)->build()['balanced'];
}

describe('the quote', function (): void {
    it('charges principal and earned interest, and forgives the rest', function (): void {
        $loan = matureLoan();

        officerAt($loan->branch->name, RoleName::BranchManager);
        $quote = settlementQuote($loan);

        $ordered = $loan->schedules->sortBy('installment_number')->values();
        $due = $ordered[0];

        $expectedPrincipal = Money::sum($loan->schedules->map(
            fn (LoanSchedule $s): Money => $s->outstandingPrincipal(),
        ));

        $futureInterest = Money::sum($loan->schedules->skip(1)->map(
            fn (LoanSchedule $s): Money => $s->outstandingInterest(),
        ));

        expect($quote['principal'])->toBe($expectedPrincipal->toDecimalString())
            // Only the matured installment's interest has been earned.
            ->and($quote['interestEarned'])->toBe($due->outstandingInterest()->toDecimalString())
            ->and($quote['interestWaived'])->toBe($futureInterest->toDecimalString())
            // Settling costs less than running to term. That is the whole point.
            ->and(Money::of($quote['payable'])->lessThan(Money::of($quote['payableIfRunToTerm'])))->toBeTrue();
    });

    it('counts an outstanding penalty in full — a penalty is not unearned', function (): void {
        $loan = matureLoan();
        $first = $loan->schedules->sortBy('installment_number')->first();
        $first->update(['penalty_due' => '4000.00', 'status' => LoanScheduleStatus::Overdue]);

        officerAt($loan->branch->name, RoleName::BranchManager);

        expect(settlementQuote($loan)['penalty'])->toBe('4000.00');
    });

    it('applies held advance credit before asking for cash', function (): void {
        $loan = activeLoan();

        // Paid ahead — Option A. The money is held, not applied.
        payCash($loan, '50000.00')->assertCreated();

        officerAt($loan->branch->name, RoleName::BranchManager);
        $quote = settlementQuote($loan);

        expect($quote['advanceHeld'])->toBe('50000.00')
            ->and(Money::of($quote['cashRequired'])->toDecimalString())
            ->toBe(Money::of($quote['payable'])->subtract(Money::of('50000.00'))->toDecimalString());
    });

    it('is readable by anyone who can see the loan, settle-grant or not', function (): void {
        $loan = matureLoan();

        // A Loan Officer cannot settle, but may legitimately have to tell a
        // customer what settling would cost.
        officerAt($loan->branch->name, RoleName::LoanOfficer);

        test()->getJson("/api/v1/loans/{$loan->id}/early-settlement")->assertOk();
    });
});

describe('settling', function (): void {
    it('closes the loan, cancels future installments and waives their interest', function (): void {
        $loan = matureLoan();

        officerAt($loan->branch->name, RoleName::BranchManager);
        $quote = settlementQuote($loan);

        $this->postJson("/api/v1/loans/{$loan->id}/early-settlement", ['amount' => $quote['cashRequired']])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $loan->refresh()->load('schedules');

        expect($loan->status)->toBe(LoanStatus::Closed)
            ->and($loan->early_settled_at)->not->toBeNull()
            ->and($loan->interest_waived)->toBe($quote['interestWaived'])
            // Nothing is owed on any row, by either the PHP or the SQL path.
            ->and($loan->outstandingTotal()->toDecimalString())->toBe('0.00')
            ->and(balancedBooks())->toBeTrue();

        $cancelled = $loan->schedules->where('status', LoanScheduleStatus::Cancelled);

        expect($cancelled)->toHaveCount((int) $quote['installmentsCancelled'])
            ->and($cancelled->every(fn (LoanSchedule $s): bool => $s->outstandingTotal()->isZero()))->toBeTrue();
    });

    it('agrees with the SQL balance aggregate, not just the PHP one', function (): void {
        /*
         * The two paths sum the same columns in different places. A settled
         * loan whose waiver only one of them knew about would show a debt on
         * the list screen and none on the detail page — and would turn up in an
         * arrears report for money the borrower has been forgiven.
         */
        $loan = matureLoan();

        officerAt($loan->branch->name, RoleName::BranchManager);
        $quote = settlementQuote($loan);

        $this->postJson("/api/v1/loans/{$loan->id}/early-settlement", ['amount' => $quote['cashRequired']])
            ->assertOk();

        $row = Loan::query()->withScheduleTotals()->findOrFail($loan->getKey());

        expect(Money::of((string) $row->schedule_due_total)
            ->subtract(Money::of((string) $row->schedule_paid_total))
            ->toDecimalString())->toBe('0.00');
    });

    it('settles entirely from a held advance when the credit already covers it', function (): void {
        $loan = matureLoan();

        officerAt($loan->branch->name, RoleName::BranchManager);
        $payable = Money::of(settlementQuote($loan)['payable']);

        // The borrower has been paying ahead and already holds enough.
        payCash($loan, $payable->toDecimalString())->assertCreated();

        officerAt($loan->branch->name, RoleName::BranchManager);

        // Not one more shilling is asked for.
        $this->postJson("/api/v1/loans/{$loan->id}/early-settlement")->assertOk();

        expect($loan->fresh()->status)->toBe(LoanStatus::Closed)
            ->and(LoanAdvance::balanceFor((int) $loan->getKey())->toDecimalString())->toBe('0.00')
            ->and(balancedBooks())->toBeTrue();
    });

    it('refuses a shortfall and names both figures', function (): void {
        $loan = matureLoan();

        officerAt($loan->branch->name, RoleName::BranchManager);

        $this->postJson("/api/v1/loans/{$loan->id}/early-settlement", ['amount' => '100.00'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_LOAN_STATE');

        // Nothing moved.
        expect($loan->fresh()->status)->toBe(LoanStatus::Active)
            ->and($loan->fresh(['schedules'])->outstandingTotal()->isPositive())->toBeTrue();
    });

    it('needs its own grant — approving loans is not enough', function (): void {
        $loan = matureLoan();

        // A Loan Officer originates but cannot discount.
        officerAt($loan->branch->name, RoleName::LoanOfficer);

        $this->postJson("/api/v1/loans/{$loan->id}/early-settlement", ['amount' => '999999.00'])
            ->assertForbidden();
    });

    it('refuses a loan that is not on the book', function (): void {
        $loan = submittedLoan();

        officerAt('Kakonko', RoleName::BranchManager);

        $this->postJson("/api/v1/loans/{$loan->id}/early-settlement", ['amount' => '0.00'])
            ->assertStatus(409);
    });

    it('records the whole thing, including what was forgiven', function (): void {
        $loan = matureLoan();

        officerAt($loan->branch->name, RoleName::BranchManager);
        $quote = settlementQuote($loan);

        $this->postJson("/api/v1/loans/{$loan->id}/early-settlement", ['amount' => $quote['cashRequired']])
            ->assertOk();

        $log = AuditLog::query()
            ->where('action', AuditAction::LoanSettledEarly->value)
            ->where('auditable_id', $loan->getKey())
            ->sole();

        expect($log->after_json['interestWaived'])->toBe($quote['interestWaived'])
            ->and($log->after_json['principal'])->toBe($quote['principal'])
            ->and($log->after_json['installmentsCancelled'])->toBe((int) $quote['installmentsCancelled'])
            ->and($log->user_id)->not->toBeNull();
    });

    it('opens the customer cooldown, exactly as an ordinary closure does', function (): void {
        $loan = matureLoan();

        officerAt($loan->branch->name, RoleName::BranchManager);
        $quote = settlementQuote($loan);

        $this->postJson("/api/v1/loans/{$loan->id}/early-settlement", ['amount' => $quote['cashRequired']])
            ->assertOk();

        // Settling early must not become a way to skip the post-closure freeze.
        expect($loan->fresh()->frozen_until)->not->toBeNull();
    });
});

describe('Option A is untouched', function (): void {
    it('still holds an early payment rather than settling on its own', function (): void {
        /*
         * The two options must stay distinct. If merely paying the balance
         * closed the loan, Option B would have no reason to exist and a
         * borrower paying ahead would lose their schedule without asking.
         */
        $loan = activeLoan();

        payCash($loan, $loan->outstandingTotal()->toDecimalString())->assertCreated();

        expect($loan->fresh()->status)->toBe(LoanStatus::Active)
            ->and($loan->fresh(['schedules'])->schedules->every(
                fn (LoanSchedule $s): bool => $s->status !== LoanScheduleStatus::Cancelled,
            ))->toBeTrue()
            ->and(LoanAdvance::balanceFor((int) $loan->getKey())->isPositive())->toBeTrue();
    });
});

describe('what the settlement record serves', function (): void {
    /*
     * The five values a settlement screen has to show — settlement date,
     * interest waived, final amount paid, reference and officer — all come
     * from the API. `amountPaid` in particular is NOT recoverable in the
     * browser: the waiver reduced the balance before the money was taken, so
     * anything the frontend arrived at by subtracting would be the amount owed
     * before forgiveness rather than the amount actually handed over.
     */
    it('returns every settlement value on the response that performed it', function (): void {
        $loan = matureLoan();

        $officer = officerAt($loan->branch->name, RoleName::BranchManager);
        $quote = settlementQuote($loan);

        $body = $this->postJson("/api/v1/loans/{$loan->id}/early-settlement", ['amount' => $quote['cashRequired']])
            ->assertOk()
            ->json('data');

        expect($body['earlySettledAt'])->not->toBeNull()
            ->and($body['interestWaived'])->toBe($quote['interestWaived'])
            ->and($body['earlySettlement']['settledAt'])->toBe($body['earlySettledAt'])
            ->and($body['earlySettlement']['interestWaived'])->toBe($quote['interestWaived'])
            ->and($body['earlySettlement']['amountPaid'])->toBe($quote['cashRequired'])
            ->and($body['earlySettlement']['reference'])->toStartWith('PAY-')
            ->and($body['earlySettlement']['officerId'])->toBe((string) $officer->getKey())
            ->and($body['earlySettlement']['officerName'])->toBe($officer->name);
    });

    it('serves the same record when the loan is read back later', function (): void {
        $loan = matureLoan();

        $officer = officerAt($loan->branch->name, RoleName::BranchManager);
        $quote = settlementQuote($loan);

        $this->postJson("/api/v1/loans/{$loan->id}/early-settlement", ['amount' => $quote['cashRequired']])->assertOk();

        // The detail page reads this endpoint, not the settle response.
        $body = $this->getJson("/api/v1/loans/{$loan->id}")->assertOk()->json('data');

        expect($body['earlySettlement']['reference'])->toStartWith('PAY-')
            ->and($body['earlySettlement']['officerName'])->toBe($officer->name)
            ->and($body['earlySettlement']['amountPaid'])->toBe($quote['cashRequired'])
            ->and($body['interestWaived'])->toBe($quote['interestWaived']);
    });

    it('records the officer and the payment on the loan itself', function (): void {
        $loan = matureLoan();

        $officer = officerAt($loan->branch->name, RoleName::BranchManager);
        $quote = settlementQuote($loan);

        $this->postJson("/api/v1/loans/{$loan->id}/early-settlement", ['amount' => $quote['cashRequired']])->assertOk();

        $loan->refresh();

        /*
         * Stored, not reconstructed. A screen that had to search the payments
         * table for "the one that settled this" could pick a different row
         * than the one that actually did.
         */
        expect($loan->early_settled_by)->toBe($officer->getKey())
            ->and($loan->early_settlement_payment_id)->not->toBeNull()
            ->and($loan->earlySettlementPayment->amount)->toBe($quote['cashRequired']);
    });

    it('says nothing was settled early on a loan that simply ran its course', function (): void {
        $loan = activeLoan();

        officerAt($loan->branch->name, RoleName::BranchManager);

        $body = $this->getJson("/api/v1/loans/{$loan->id}")->assertOk()->json('data');

        /*
         * Null and "0.00", not absent. The two fields describe the loan, and a
         * consumer must be able to ask "was this settled early" of any loan.
         */
        expect($body['earlySettledAt'])->toBeNull()
            ->and($body['interestWaived'])->toBe('0.00')
            ->and($body['earlySettlement'])->toBeNull();
    });
});
