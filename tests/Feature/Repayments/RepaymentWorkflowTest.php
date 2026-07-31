<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Ledger\Services\TrialBalanceBuilder;
use App\Domain\Loans\Enums\LoanScheduleStatus;
use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Repayments\Services\RepaymentPostingBuilder;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Models\Payment;
use App\Support\Money;

beforeEach(function (): void {
    seedLedgerFoundation();
});

describe('disbursement completion', function (): void {
    it('activates the loan only after the entry is posted', function (): void {
        $loan = loanAtFinance();

        officerAt('Head Office', RoleName::Finance);
        $this->postJson("/api/v1/loans/{$loan->id}/prepare-disbursement", ['channel' => 'vodacom'])->assertCreated();

        // §6: no ledger entry exists until a batch reaches success.
        expect($loan->fresh()->status)->toBe(LoanStatus::AwaitingDisbursement)
            ->and(JournalEntry::query()->where('source_id', $loan->getKey())->exists())->toBeFalse();

        $this->postJson("/api/v1/loans/{$loan->id}/settle-disbursement", ['success' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $entry = JournalEntry::query()->with('lines')
            ->where('source_type', 'loan_disbursement')
            ->where('source_id', $loan->getKey())
            ->sole();

        expect($entry->isBalanced())->toBeTrue()
            ->and($entry->totalDebits()->toDecimalString())->toBe($loan->principal()->toDecimalString());
    });

    it('posts Dr Loan Receivable and Cr Principal for the principal', function (): void {
        $loan = activeLoan();
        $accounts = app(AccountResolver::class);

        $entry = JournalEntry::query()->with('lines')
            ->where('source_type', 'loan_disbursement')
            ->where('source_id', $loan->getKey())
            ->sole();

        $debit = $entry->lines->firstWhere('account_id', $accounts->systemId(SystemAccountCode::LoanReceivable));
        $credit = $entry->lines->firstWhere('account_id', $accounts->systemId(SystemAccountCode::Principal));

        expect($debit->debit_amount)->toBe($loan->principal()->toDecimalString())
            ->and($credit->credit_amount)->toBe($loan->principal()->toDecimalString());
    });

    it('stamps the disbursement and expected completion dates', function (): void {
        $loan = activeLoan();

        expect($loan->disbursement_date->toDateString())->toBe(now()->toDateString())
            ->and($loan->expected_completion_date->toDateString())
            ->toBe(now()->addDays($loan->tenure_days)->toDateString());
    });

    it('leaves the loan inactive and the books empty when the callback fails', function (): void {
        $loan = loanAtFinance();

        officerAt('Head Office', RoleName::Finance);
        $this->postJson("/api/v1/loans/{$loan->id}/prepare-disbursement", ['channel' => 'vodacom'])->assertCreated();

        $this->postJson("/api/v1/loans/{$loan->id}/settle-disbursement", [
            'success' => false,
            'failureReason' => 'Provider returned INSUFFICIENT_FLOAT',
        ])->assertOk()->assertJsonPath('data.status', 'disbursement_failed');

        // No money moved, so there is nothing to record.
        expect(JournalEntry::query()->where('source_id', $loan->getKey())->exists())->toBeFalse();
    });

    it('ignores a repeated success callback', function (): void {
        $loan = activeLoan();

        // Providers retry callbacks routinely; the batch status is the
        // idempotency marker.
        $this->postJson("/api/v1/loans/{$loan->id}/settle-disbursement", ['success' => true])
            ->assertStatus(409);

        expect(JournalEntry::query()->where('source_type', 'loan_disbursement')
            ->where('source_id', $loan->getKey())->count())->toBe(1);
    });

    it('settles through the provider webhook by batch reference', function (): void {
        $loan = loanAtFinance();

        officerAt('Head Office', RoleName::Finance);
        $reference = $this->postJson("/api/v1/loans/{$loan->id}/prepare-disbursement", ['channel' => 'vodacom'])
            ->assertCreated()
            ->json('data.batchReference');

        forgetAuthGuards();

        // Unauthenticated, exactly as a provider callback arrives.
        postDisbursementWebhook(['batchReference' => $reference, 'success' => true])->assertOk();

        expect($loan->fresh()->status)->toBe(LoanStatus::Active);
    });
});

describe('allocation', function (): void {
    it('applies penalty then interest then principal, oldest installment first', function (): void {
        $loan = activeLoan();

        $first = $loan->schedules->sortBy('installment_number')->first();
        $first->update(['penalty_due' => '5000.00', 'status' => LoanScheduleStatus::Overdue]);

        // Exactly the first installment's penalty + interest, and nothing
        // towards principal — so the order is visible in the result.
        $amount = Money::of('5000.00')->add($first->interestDue());

        officerAt($loan->branch->name, RoleName::Teller);
        $this->postJson('/api/v1/payments/cash', [
            'loanId' => $loan->getKey(),
            'amount' => $amount->toDecimalString(),
        ])->assertCreated();

        $first->refresh();

        expect($first->penalty_paid)->toBe('5000.00')
            ->and($first->interest_paid)->toBe($first->interestDue()->toDecimalString())
            ->and($first->principal_paid)->toBe('0.00')
            ->and($first->status)->toBe(LoanScheduleStatus::Partial);
    });

    it('clears an installment fully before moving to the next', function (): void {
        $loan = activeLoan();

        $ordered = $loan->schedules->sortBy('installment_number')->values();
        $amount = $ordered[0]->totalDue()->add(Money::of('1000.00'));

        officerAt($loan->branch->name, RoleName::Teller);
        $this->postJson('/api/v1/payments/cash', [
            'loanId' => $loan->getKey(),
            'amount' => $amount->toDecimalString(),
        ])->assertCreated();

        expect($ordered[0]->fresh()->status)->toBe(LoanScheduleStatus::Paid)
            // The 1,000 spilled onto the second installment's penalty (nil),
            // then its interest.
            ->and($ordered[1]->fresh()->totalPaid()->toDecimalString())->toBe('1000.00');
    });

    it('writes one allocation row per installment touched', function (): void {
        $loan = activeLoan();

        $amount = installmentTotal($loan, 2);

        officerAt($loan->branch->name, RoleName::Teller);
        $paymentId = $this->postJson('/api/v1/payments/cash', [
            'loanId' => $loan->getKey(),
            'amount' => $amount->toDecimalString(),
        ])->assertCreated()->json('data.id');

        expect(Payment::query()->findOrFail($paymentId)->allocations)->toHaveCount(2);
    });

    it('leaves a partial payment outstanding without closing the installment', function (): void {
        $loan = activeLoan();
        $first = $loan->schedules->sortBy('installment_number')->first();

        officerAt($loan->branch->name, RoleName::Teller);
        $this->postJson('/api/v1/payments/cash', [
            'loanId' => $loan->getKey(),
            'amount' => '1000.00',
        ])->assertCreated();

        $first->refresh();

        expect($first->status)->toBe(LoanScheduleStatus::Partial)
            ->and($first->outstandingTotal()->toDecimalString())
            ->toBe($first->totalDue()->subtract(Money::of('1000.00'))->toDecimalString())
            ->and($loan->fresh()->status)->toBe(LoanStatus::Active);
    });

    it('never lets a schedule row record more paid than due', function (): void {
        $loan = activeLoan();

        // Deliberately far more than the loan owes.
        officerAt($loan->branch->name, RoleName::Teller);
        $this->postJson('/api/v1/payments/cash', [
            'loanId' => $loan->getKey(),
            'amount' => $loan->outstandingTotal()->add(Money::of('250000.00'))->toDecimalString(),
        ])->assertCreated();

        $overpaid = LoanSchedule::query()->where('loan_id', $loan->getKey())->get()
            ->filter(fn (LoanSchedule $s): bool => $s->totalPaid()->greaterThan($s->totalDue()));

        expect($overpaid)->toBeEmpty();
    });
});

describe('settlement', function (): void {
    it('closes the loan and opens the cooldown when it is fully repaid', function (): void {
        $loan = activeLoan();

        officerAt($loan->branch->name, RoleName::Teller);
        $this->postJson('/api/v1/payments/cash', [
            'loanId' => $loan->getKey(),
            'amount' => $loan->outstandingTotal()->toDecimalString(),
        ])->assertCreated();

        $loan->refresh();

        expect($loan->status)->toBe(LoanStatus::Closed)
            ->and($loan->closed_at)->not->toBeNull()
            // §10's post-closure freeze window lives on the customer's next
            // application, which is why it is stamped here.
            ->and($loan->frozen_until)->not->toBeNull();
    });

    it('settles early in one payment, ahead of every due date', function (): void {
        $loan = activeLoan();

        // Every installment is still in the future — an early settlement, not
        // a final one.
        expect($loan->schedules->every(fn (LoanSchedule $s): bool => $s->due_date->isFuture()))->toBeTrue();

        officerAt($loan->branch->name, RoleName::Teller);
        $this->postJson('/api/v1/payments/cash', [
            'loanId' => $loan->getKey(),
            'amount' => $loan->outstandingTotal()->toDecimalString(),
        ])->assertCreated();

        expect($loan->fresh()->status)->toBe(LoanStatus::Closed)
            ->and($loan->fresh(['schedules'])->outstandingTotal()->isZero())->toBeTrue();
    });

    it('refuses a payment against a closed loan', function (): void {
        $loan = activeLoan();

        officerAt($loan->branch->name, RoleName::Teller);
        $this->postJson('/api/v1/payments/cash', [
            'loanId' => $loan->getKey(),
            'amount' => $loan->outstandingTotal()->toDecimalString(),
        ])->assertCreated();

        $this->postJson('/api/v1/payments/cash', ['loanId' => $loan->getKey(), 'amount' => '1000.00'])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'LOAN_NOT_REPAYABLE');
    });
});

describe('repayment postings', function (): void {
    it('credits penalty, interest and principal separately', function (): void {
        $loan = activeLoan();
        $accounts = app(AccountResolver::class);

        $first = $loan->schedules->sortBy('installment_number')->first();
        $first->update(['penalty_due' => '4000.00', 'status' => LoanScheduleStatus::Overdue]);

        officerAt($loan->branch->name, RoleName::Teller);
        $this->postJson('/api/v1/payments/cash', [
            'loanId' => $loan->getKey(),
            'amount' => $first->fresh()->totalDue()->toDecimalString(),
        ])->assertCreated();

        $entry = JournalEntry::query()->with('lines')->where('source_type', 'repayment')->latest('id')->firstOrFail();

        $creditOn = fn (SystemAccountCode $code): string => $entry->lines
            ->firstWhere('account_id', $accounts->systemId($code))?->credit_amount ?? '0.00';

        expect($creditOn(SystemAccountCode::PenaltyIncome))->toBe('4000.00')
            ->and($creditOn(SystemAccountCode::InterestIncome))->toBe($first->interestDue()->toDecimalString())
            ->and($creditOn(SystemAccountCode::LoanReceivable))->toBe($first->principalDue()->toDecimalString())
            ->and($entry->isBalanced())->toBeTrue();
    });

    it('takes the 10% reserve cut on the same entry as the interest', function (): void {
        $loan = activeLoan();
        $accounts = app(AccountResolver::class);
        $first = $loan->schedules->sortBy('installment_number')->first();

        officerAt($loan->branch->name, RoleName::Teller);
        $this->postJson('/api/v1/payments/cash', [
            'loanId' => $loan->getKey(),
            'amount' => $first->totalDue()->toDecimalString(),
        ])->assertCreated();

        $entry = JournalEntry::query()->with('lines')->where('source_type', 'repayment')->latest('id')->firstOrFail();

        $expected = app(RepaymentPostingBuilder::class)->reserveCut($first->interestDue());

        $reserve = $entry->lines->firstWhere('account_id', $accounts->systemId(SystemAccountCode::Reserve));
        $interestDebit = $entry->lines->first(
            fn ($l): bool => $l->account_id === $accounts->systemId(SystemAccountCode::InterestIncome)
                && $l->debitAmount()->isPositive(),
        );

        // §5: Dr Interest Income · Cr Reserve, on every interest collection.
        expect($reserve->credit_amount)->toBe($expected->toDecimalString())
            ->and($interestDebit->debit_amount)->toBe($expected->toDecimalString())
            // Gross interest is still visible: the cut is a second pair of
            // lines, not a netted-down income line.
            ->and($entry->isBalanced())->toBeTrue();
    });

    it('debits teller cash for a cash payment and the bank for a provider one', function (): void {
        $loan = activeLoan();
        $accounts = app(AccountResolver::class);

        officerAt($loan->branch->name, RoleName::Teller);
        $this->postJson('/api/v1/payments/cash', ['loanId' => $loan->getKey(), 'amount' => '10000.00'])
            ->assertCreated();

        $cashEntry = JournalEntry::query()->with('lines')->where('source_type', 'repayment')->latest('id')->firstOrFail();
        $tellerCash = $accounts->tellerCash($loan->branch);

        expect($cashEntry->lines->firstWhere('account_id', $tellerCash->getKey())?->debit_amount)->toBe('10000.00');

        forgetAuthGuards();
        postPaymentWebhook([
            'reference' => $loan->loan_number,
            'amount' => '10000.00',
            'channel' => 'mobile_money',
            'transactionId' => 'TXN-CHANNEL-1',
        ])->assertOk();

        $providerEntry = JournalEntry::query()->with('lines')->where('source_type', 'repayment')->latest('id')->firstOrFail();

        expect($providerEntry->lines->firstWhere('account_id', $accounts->defaultBankAccount()->getKey())?->debit_amount)
            ->toBe('10000.00');
    });

    it('keeps the trial balance balanced through a full repayment cycle', function (): void {
        $loan = activeLoan();

        officerAt($loan->branch->name, RoleName::Teller);

        foreach (['5000.00', '25000.00', '100000.00'] as $amount) {
            $this->postJson('/api/v1/payments/cash', ['loanId' => $loan->getKey(), 'amount' => $amount])
                ->assertCreated();
        }

        $tb = app(TrialBalanceBuilder::class)->build();

        expect($tb['balanced'])->toBeTrue();
    });
});

describe('the provider webhook', function (): void {
    it('matches on loan number and allocates', function (): void {
        $loan = activeLoan();
        forgetAuthGuards();

        $response = postPaymentWebhook([
            'reference' => $loan->loan_number,
            'amount' => '20000.00',
            'channel' => 'mobile_money',
            'transactionId' => 'TXN-MATCH-1',
        ])->assertOk();

        expect($response->json('data.status'))->toBe('allocated')
            ->and($response->json('data.allocation'))->not->toBeEmpty();
    });

    it('returns 200 with an unmatched payment when the reference is unknown', function (): void {
        activeLoan();
        forgetAuthGuards();

        // §15.3: still 200 — the payment was received and ledgered to
        // Suspense; it is unmatched, not failed.
        postPaymentWebhook([
            'reference' => 'LN-2026-999999',
            'amount' => '20000.00',
            'channel' => 'mobile_money',
            'transactionId' => 'TXN-MISS-1',
        ])->assertOk()->assertJsonPath('data.status', 'unmatched');

        $payment = Payment::query()->where('transaction_id', 'TXN-MISS-1')->sole();

        expect($payment->suspenseItem)->not->toBeNull()
            // Nothing sits un-ledgered: Dr Bank · Cr Suspense on arrival.
            ->and($payment->journal_entry_id)->not->toBeNull();
    });

    it('rejects a duplicate transaction id', function (): void {
        $loan = activeLoan();
        forgetAuthGuards();

        $payload = [
            'reference' => $loan->loan_number,
            'amount' => '20000.00',
            'channel' => 'mobile_money',
            'transactionId' => 'TXN-DUPE-1',
        ];

        postPaymentWebhook($payload)->assertOk();

        /*
         * Deliberately a fresh signature rather than a byte-identical resend:
         * an identical replay would be caught by the Idempotency-Key layer and
         * would never reach the duplicate check this test is about.
         */
        postPaymentWebhook($payload)
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'DUPLICATE_TRANSACTION');

        expect(Payment::query()->where('transaction_id', 'TXN-DUPE-1')->count())->toBe(1);
    });

    it('suspends money aimed at a loan that cannot take it', function (): void {
        $loan = activeLoan();

        officerAt($loan->branch->name, RoleName::Teller);
        $this->postJson('/api/v1/payments/cash', [
            'loanId' => $loan->getKey(),
            'amount' => $loan->outstandingTotal()->toDecimalString(),
        ])->assertCreated();

        forgetAuthGuards();

        postPaymentWebhook([
            'reference' => $loan->loan_number,
            'amount' => '20000.00',
            'channel' => 'mobile_money',
            'transactionId' => 'TXN-CLOSED-1',
        ])->assertOk()->assertJsonPath('data.status', 'unmatched');
    });
});

describe('suspense', function (): void {
    it('draws suspense down rather than debiting cash twice on resolution', function (): void {
        $loan = activeLoan();
        $accounts = app(AccountResolver::class);

        $finance = officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/payments/unmatched', [
            'amount' => '30000.00',
            'channel' => 'bank',
            'reason' => 'Bank credit with no reference',
        ])->assertCreated();

        $item = App\Models\SuspenseItem::query()->latest('id')->firstOrFail();

        $suspenseId = $accounts->systemId(SystemAccountCode::Suspense);
        $arrival = JournalEntry::query()->with('lines')->latest('id')->firstOrFail();

        expect($arrival->lines->firstWhere('account_id', $suspenseId)?->credit_amount)->toBe('30000.00');

        $this->postJson("/api/v1/payments/suspense/{$item->id}/allocate", ['loanId' => $loan->getKey()])
            ->assertOk()
            ->assertJsonPath('data.status', 'allocated');

        $resolution = JournalEntry::query()->with('lines')
            ->where('source_type', 'suspense_resolution')->latest('id')->firstOrFail();

        // Dr Suspense on the second entry — the cash debit already happened.
        expect($resolution->lines->firstWhere('account_id', $suspenseId)?->debit_amount)->toBe('30000.00')
            ->and($resolution->isBalanced())->toBeTrue();
    });

    it('leaves the original entry untouched when resolving', function (): void {
        $loan = activeLoan();
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/payments/unmatched', [
            'amount' => '30000.00',
            'channel' => 'bank',
            'reason' => 'Bank credit with no reference',
        ])->assertCreated();

        $item = App\Models\SuspenseItem::query()->latest('id')->firstOrFail();
        $original = JournalEntry::query()->with('lines')->latest('id')->firstOrFail();
        $snapshot = $original->lines->map->only(['account_id', 'debit_amount', 'credit_amount'])->all();

        $this->postJson("/api/v1/payments/suspense/{$item->id}/allocate", ['loanId' => $loan->getKey()])->assertOk();

        expect($original->fresh(['lines'])->lines->map->only(['account_id', 'debit_amount', 'credit_amount'])->all())
            ->toBe($snapshot);
    });

    it('nets suspense back to zero once every item is resolved', function (): void {
        $loan = activeLoan();
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/payments/unmatched', [
            'amount' => '30000.00',
            'channel' => 'bank',
            'reason' => 'Bank credit with no reference',
        ])->assertCreated();

        $item = App\Models\SuspenseItem::query()->latest('id')->firstOrFail();
        $this->postJson("/api/v1/payments/suspense/{$item->id}/allocate", ['loanId' => $loan->getKey()])->assertOk();

        $rows = collect(app(TrialBalanceBuilder::class)->build()['rows'])->keyBy('code');

        expect($rows[SystemAccountCode::Suspense->value]['balance'])->toBe('0.00');
    });

    it('refuses to resolve the same item twice', function (): void {
        $loan = activeLoan();
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/payments/unmatched', [
            'amount' => '30000.00',
            'channel' => 'bank',
            'reason' => 'Bank credit with no reference',
        ])->assertCreated();

        $item = App\Models\SuspenseItem::query()->latest('id')->firstOrFail();
        $this->postJson("/api/v1/payments/suspense/{$item->id}/allocate", ['loanId' => $loan->getKey()])->assertOk();

        $this->postJson("/api/v1/payments/suspense/{$item->id}/allocate", ['loanId' => $loan->getKey()])
            ->assertStatus(409);
    });

    it('lists only unresolved items in the queue', function (): void {
        $loan = activeLoan();
        officerAt('Head Office', RoleName::Finance);

        foreach (['10000.00', '20000.00'] as $amount) {
            $this->postJson('/api/v1/payments/unmatched', [
                'amount' => $amount,
                'channel' => 'bank',
                'reason' => 'Bank credit with no reference',
            ])->assertCreated();
        }

        expect($this->getJson('/api/v1/payments/suspense')->assertOk()->json('meta.total'))->toBe(2);

        $item = App\Models\SuspenseItem::query()->latest('id')->firstOrFail();
        $this->postJson("/api/v1/payments/suspense/{$item->id}/allocate", ['loanId' => $loan->getKey()])->assertOk();

        expect($this->getJson('/api/v1/payments/suspense')->assertOk()->json('meta.total'))->toBe(1);
    });
});

describe('penalties', function (): void {
    it('charges an overdue installment and moves the loan into arrears', function (): void {
        $loan = activeLoan();

        // Age the first installment past its grace period.
        $first = $loan->schedules->sortBy('installment_number')->first();
        $first->update(['due_date' => now()->subDays(30)->toDateString()]);

        officerAt('Head Office', RoleName::Finance);
        $response = $this->postJson('/api/v1/loans/overdue/process')->assertOk();

        $first->refresh();

        expect($first->status)->toBe(LoanScheduleStatus::Overdue)
            ->and(Money::of($first->penalty_due)->isPositive())->toBeTrue()
            ->and($loan->fresh()->status)->toBe(LoanStatus::Arrears)
            ->and($response->json('data.loansProcessed'))->toBeGreaterThan(0);
    });

    it('tops the penalty up to the computed figure rather than adding it again', function (): void {
        $loan = activeLoan();
        $first = $loan->schedules->sortBy('installment_number')->first();
        $first->update(['due_date' => now()->subDays(30)->toDateString()]);

        officerAt('Head Office', RoleName::Finance);
        $this->postJson('/api/v1/loans/overdue/process')->assertOk();

        $charged = Money::of($first->fresh()->penalty_due);

        $second = $this->postJson('/api/v1/loans/overdue/process')->assertOk();

        $after = Money::of($first->fresh()->penalty_due);

        // The run reports exactly the shortfall it charged, and the recorded
        // figure moves by that much — never by the whole penalty a second
        // time, which is what "must not stack" rules out.
        expect($second->json('data.totalPenaltyApplied'))
            ->toBe($after->subtract($charged)->toDecimalString())
            ->and($after->lessThan($charged->add($charged)))->toBeTrue();
    });

    it('grows on a repeated run because the base includes the accrued penalty (OSC-4)', function (): void {
        $loan = activeLoan();
        $first = $loan->schedules->sortBy('installment_number')->first();
        $first->update(['due_date' => now()->subDays(30)->toDateString()]);

        officerAt('Head Office', RoleName::Finance);
        $this->postJson('/api/v1/loans/overdue/process')->assertOk();

        $charged = Money::of($first->fresh()->penalty_due);

        $second = $this->postJson('/api/v1/loans/overdue/process')->assertOk();

        /*
         * Pinned deliberately, not endorsed. §7 does not say whether the
         * penalty base includes a penalty already accrued; the frontend's
         * `scheduleOutstanding().total` does include it, so a same-day re-run
         * tops up by the penalty-on-penalty. This test exists so that changing
         * the base — which is the other half of resolving OSC-4 — is a
         * deliberate decision that breaks a named test, rather than a silent
         * change to what every borrower owes.
         */
        expect(Money::of($first->fresh()->penalty_due)->greaterThan($charged))->toBeTrue()
            ->and($second->json('data.totalPenaltyApplied'))->not->toBe('0.00');
    });

    it('posts nothing to the ledger on accrual (OSC-1)', function (): void {
        $loan = activeLoan();
        $loan->schedules->sortBy('installment_number')->first()
            ->update(['due_date' => now()->subDays(30)->toDateString()]);

        $before = JournalEntry::query()->count();

        officerAt('Head Office', RoleName::Finance);
        $response = $this->postJson('/api/v1/loans/overdue/process')->assertOk();

        // Penalty income is recognised on collection; posting on accrual too
        // would double-count it.
        expect(JournalEntry::query()->count())->toBe($before)
            ->and($response->json('data.ledgerPosting'))->toContain('OSC-1');
    });

    it('recognises the penalty as income when it is collected', function (): void {
        $loan = activeLoan();
        $accounts = app(AccountResolver::class);
        $first = $loan->schedules->sortBy('installment_number')->first();
        $first->update(['due_date' => now()->subDays(30)->toDateString()]);

        officerAt('Head Office', RoleName::Finance);
        $this->postJson('/api/v1/loans/overdue/process')->assertOk();

        $penalty = Money::of($first->fresh()->penalty_due);

        officerAt($loan->branch->name, RoleName::Teller);
        $this->postJson('/api/v1/payments/cash', [
            'loanId' => $loan->getKey(),
            'amount' => $penalty->toDecimalString(),
        ])->assertCreated();

        $entry = JournalEntry::query()->with('lines')->where('source_type', 'repayment')->latest('id')->firstOrFail();

        expect($entry->lines->firstWhere('account_id', $accounts->systemId(SystemAccountCode::PenaltyIncome))?->credit_amount)
            ->toBe($penalty->toDecimalString());
    });

    it('returns the loan to active once the arrears are cleared', function (): void {
        $loan = activeLoan();
        $first = $loan->schedules->sortBy('installment_number')->first();
        $first->update(['due_date' => now()->subDays(30)->toDateString()]);

        officerAt('Head Office', RoleName::Finance);
        $this->postJson('/api/v1/loans/overdue/process')->assertOk();

        expect($loan->fresh()->status)->toBe(LoanStatus::Arrears);

        officerAt($loan->branch->name, RoleName::Teller);
        $this->postJson('/api/v1/payments/cash', [
            'loanId' => $loan->getKey(),
            'amount' => $first->fresh()->outstandingTotal()->toDecimalString(),
        ])->assertCreated();

        expect($loan->fresh()->status)->toBe(LoanStatus::Active);
    });

    it('records the run', function (): void {
        activeLoan();

        officerAt('Head Office', RoleName::Finance);
        $this->postJson('/api/v1/loans/overdue/process')->assertOk();

        expect(App\Models\PenaltyRun::query()->count())->toBe(1)
            ->and(App\Models\PenaltyRun::query()->value('triggered_by'))->toBe(App\Domain\Repayments\Enums\TriggeredBy::Manual);
    });
});

describe('RBAC and branch scope', function (): void {
    it('lets a Teller record cash and nothing else', function (): void {
        $loan = activeLoan();

        officerAt($loan->branch->name, RoleName::Teller);

        // §14: "Cash payment entry only, no reconciliation, no reversal."
        $this->postJson('/api/v1/payments/cash', ['loanId' => $loan->getKey(), 'amount' => '5000.00'])
            ->assertCreated();

        $this->getJson('/api/v1/payments/suspense')->assertForbidden();
        $this->postJson('/api/v1/loans/overdue/process')->assertForbidden();
        $this->postJson('/api/v1/payments/unmatched', [
            'amount' => '1000.00', 'channel' => 'bank', 'reason' => 'Anything',
        ])->assertForbidden();
    });

    it('refuses a Loan Officer the cash-entry grant', function (): void {
        $loan = activeLoan();

        officerAt($loan->branch->name, RoleName::LoanOfficer);

        $this->postJson('/api/v1/payments/cash', ['loanId' => $loan->getKey(), 'amount' => '5000.00'])
            ->assertForbidden();
    });

    it('blocks a teller from taking cash for another branch', function (): void {
        $loan = activeLoan();

        officerAt('Missenyi', RoleName::Teller);

        $this->postJson('/api/v1/payments/cash', ['loanId' => $loan->getKey(), 'amount' => '5000.00'])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'BRANCH_SCOPE_VIOLATION');

        expect(AuditLog::query()->where('action', AuditAction::BranchScopeViolation->value)->exists())->toBeTrue();
    });

    it('hides another branch payments from a branch-scoped user', function (): void {
        $loan = activeLoan();

        officerAt($loan->branch->name, RoleName::Teller);
        $this->postJson('/api/v1/payments/cash', ['loanId' => $loan->getKey(), 'amount' => '5000.00'])
            ->assertCreated();

        officerAt('Missenyi', RoleName::BranchManager);

        expect($this->getJson('/api/v1/payments')->assertOk()->json('data'))->toBeEmpty();

        officerAt('Head Office', RoleName::Finance);

        expect($this->getJson('/api/v1/payments')->assertOk()->json('data'))->not->toBeEmpty();
    });
});

describe('audit logging', function (): void {
    it('records receipt and allocation of every payment', function (): void {
        $loan = activeLoan();

        officerAt($loan->branch->name, RoleName::Teller);
        $this->postJson('/api/v1/payments/cash', ['loanId' => $loan->getKey(), 'amount' => '5000.00'])
            ->assertCreated();

        expect(AuditLog::query()->where('action', AuditAction::PaymentReceived->value)->exists())->toBeTrue()
            ->and(AuditLog::query()->where('action', AuditAction::PaymentAllocated->value)->exists())->toBeTrue();
    });

    it('records the journal entry number alongside the allocation', function (): void {
        $loan = activeLoan();

        officerAt($loan->branch->name, RoleName::Teller);
        $this->postJson('/api/v1/payments/cash', ['loanId' => $loan->getKey(), 'amount' => '5000.00'])
            ->assertCreated();

        $log = AuditLog::query()->where('action', AuditAction::PaymentAllocated->value)->latest('id')->firstOrFail();

        expect($log->after_json['journal_entry'])->toStartWith('JE-');
    });

    it('records an unmatched receipt and its resolution', function (): void {
        $loan = activeLoan();
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/payments/unmatched', [
            'amount' => '30000.00', 'channel' => 'bank', 'reason' => 'No reference',
        ])->assertCreated();

        $item = App\Models\SuspenseItem::query()->latest('id')->firstOrFail();
        $this->postJson("/api/v1/payments/suspense/{$item->id}/allocate", ['loanId' => $loan->getKey()])->assertOk();

        expect(AuditLog::query()->where('action', AuditAction::PaymentUnmatched->value)->exists())->toBeTrue()
            ->and(AuditLog::query()->where('action', AuditAction::SuspenseResolved->value)->exists())->toBeTrue();
    });
});
