<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Ledger\Services\TrialBalanceBuilder;
use App\Domain\Loans\Enums\LoanStatus;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\WriteOff;

/**
 * Bad debt — §5's Write-Off (4200) and Recovered Loans (4300).
 *
 * Both statuses and both account codes already existed; nothing could move a
 * loan into them or post the entries §5 defines, so the Recovery report listed
 * loan STATES rather than ledger balances.
 */
beforeEach(function (): void {
    seedLedgerFoundation();
});

/** An active loan driven into default, which is the only write-off entry point. */
function defaultedLoan(): Loan
{
    $loan = activeLoan();

    // Straight to defaulted: the arrears→default transition is its own piece of
    // work, and what is under test here is what happens after default.
    $loan->update(['status' => LoanStatus::Defaulted]);

    return $loan->fresh(['schedules']);
}

function financeUser(): void
{
    officerAt('Head Office', RoleName::Finance);
}

it('posts Dr Write-Off Expense and Cr Loan Receivable for the outstanding principal', function (): void {
    $loan = defaultedLoan();
    $accounts = app(AccountResolver::class);

    $expectedPrincipal = $loan->schedules
        ->reduce(fn ($carry, $s) => $carry->add($s->outstandingPrincipal()), App\Support\Money::zero());

    financeUser();
    $response = test()->postJson("/api/v1/loans/{$loan->id}/write-off", [
        'reason' => 'Borrower untraceable after twelve months of recovery attempts.',
    ])->assertCreated();

    expect($response->json('data.principalWrittenOff'))->toBe($expectedPrincipal->toDecimalString());

    $writeOff = WriteOff::query()->where('loan_id', $loan->getKey())->sole();
    $entry = JournalEntry::query()->with('lines')->findOrFail($writeOff->journal_entry_id);

    $expense = $entry->lines->firstWhere('account_id', $accounts->systemId(SystemAccountCode::WriteOff));
    $receivable = $entry->lines->firstWhere('account_id', $accounts->systemId(SystemAccountCode::LoanReceivable));

    expect($expense->debit_amount)->toBe($expectedPrincipal->toDecimalString())
        ->and($receivable->credit_amount)->toBe($expectedPrincipal->toDecimalString())
        ->and($entry->isBalanced())->toBeTrue();
});

it('records forgone interest and penalty without posting them', function (): void {
    $loan = defaultedLoan();

    $first = $loan->schedules->sortBy('installment_number')->first();
    $first->update(['penalty_due' => '5000.00']);

    financeUser();
    test()->postJson("/api/v1/loans/{$loan->id}/write-off", [
        'reason' => 'Borrower untraceable after twelve months of recovery attempts.',
    ])->assertCreated();

    $writeOff = WriteOff::query()->where('loan_id', $loan->getKey())->sole();
    $entry = JournalEntry::query()->with('lines')->findOrFail($writeOff->journal_entry_id);

    /*
     * This system recognises interest and penalty on COLLECTION, not accrual
     * (the reading OSC-1 settled). Uncollected interest was never recognised as
     * income, so there is no revenue to reverse — writing it off would debit an
     * expense against earnings the books never carried.
     *
     * It is still recorded on the row, because the recovery officer negotiating
     * a settlement needs to know what the borrower actually owed.
     */
    expect($writeOff->penalty_forgone)->toBe('5000.00')
        ->and($writeOff->interest_forgone)->not->toBe('0.00');

    expect($entry->lines)->toHaveCount(2)
        ->and($entry->totalDebits()->toDecimalString())->toBe($writeOff->principal_written_off);
});

it('moves the loan to written off and leaves the books balanced', function (): void {
    $loan = defaultedLoan();

    financeUser();
    test()->postJson("/api/v1/loans/{$loan->id}/write-off", [
        'reason' => 'Borrower untraceable after twelve months of recovery attempts.',
    ])->assertCreated();

    expect($loan->fresh()->status)->toBe(LoanStatus::WrittenOff)
        ->and(app(TrialBalanceBuilder::class)->build()['balanced'])->toBeTrue();
});

it('refuses to write off a loan that is not defaulted', function (): void {
    $loan = activeLoan();

    financeUser();

    // §5 puts write-off at the end of a progression. Skipping to it from
    // `active` would forgive a loan nobody established was uncollectable.
    test()->postJson("/api/v1/loans/{$loan->id}/write-off", [
        'reason' => 'Borrower untraceable after twelve months of recovery attempts.',
    ])->assertStatus(409);
});

it('refuses to write off the same loan twice', function (): void {
    $loan = defaultedLoan();

    financeUser();
    $payload = ['reason' => 'Borrower untraceable after twelve months of recovery attempts.'];

    test()->postJson("/api/v1/loans/{$loan->id}/write-off", $payload)->assertCreated();

    // A second write-off would double the expense and clear an already-cleared
    // receivable.
    test()->postJson("/api/v1/loans/{$loan->id}/write-off", $payload)->assertStatus(409);
});

it('requires a reason with substance', function (): void {
    $loan = defaultedLoan();

    financeUser();

    // The only account of why the decision was made, read by an auditor long
    // after everyone involved has forgotten.
    test()->postJson("/api/v1/loans/{$loan->id}/write-off", ['reason' => 'bad'])
        ->assertStatus(422);
});

it('denies write-off to a role without the grant', function (): void {
    $loan = defaultedLoan();

    // The role that originates a loan must not be the role that can forgive it.
    officerAt($loan->branch->name, RoleName::BranchManager);

    test()->postJson("/api/v1/loans/{$loan->id}/write-off", [
        'reason' => 'Borrower untraceable after twelve months of recovery attempts.',
    ])->assertForbidden();
});

it('posts Dr Bank and Cr Recovered Loans when money comes back', function (): void {
    $loan = defaultedLoan();
    $accounts = app(AccountResolver::class);

    financeUser();
    test()->postJson("/api/v1/loans/{$loan->id}/write-off", [
        'reason' => 'Borrower untraceable after twelve months of recovery attempts.',
    ])->assertCreated();

    $response = test()->postJson("/api/v1/loans/{$loan->id}/recovery", [
        'amount' => '25000.00',
        'narrative' => 'Settlement negotiated by the recovery officer.',
    ])->assertCreated();

    $entry = JournalEntry::query()->with('lines')
        ->findOrFail($response->json('data.journalEntryId'));

    $recovered = $entry->lines->firstWhere('account_id', $accounts->systemId(SystemAccountCode::RecoveredLoans));
    $bank = $entry->lines->firstWhere('account_id', (int) $accounts->defaultBankAccount()->getKey());

    /*
     * Not a repayment. The receivable is gone, so crediting it again would
     * drive the account negative — and the schedules it would allocate against
     * no longer represent anything the books carry.
     */
    expect($recovered->credit_amount)->toBe('25000.00')
        ->and($bank->debit_amount)->toBe('25000.00')
        ->and($entry->isBalanced())->toBeTrue();
});

it('moves the loan to recovered on the first recovery only', function (): void {
    $loan = defaultedLoan();

    financeUser();
    test()->postJson("/api/v1/loans/{$loan->id}/write-off", [
        'reason' => 'Borrower untraceable after twelve months of recovery attempts.',
    ])->assertCreated();

    test()->postJson("/api/v1/loans/{$loan->id}/recovery", ['amount' => '10000.00'])->assertCreated();
    expect($loan->fresh()->status)->toBe(LoanStatus::Recovered);

    // A written-off loan may be recovered in instalments over a long period,
    // and `recovered → recovered` is not a legal transition.
    test()->postJson("/api/v1/loans/{$loan->id}/recovery", ['amount' => '15000.00'])->assertCreated();

    expect($loan->fresh()->status)->toBe(LoanStatus::Recovered)
        ->and(test()->getJson("/api/v1/loans/{$loan->id}/recoveries")->json('meta.total'))
        ->toBe('25000.00');
});

it('tracks what is still being chased', function (): void {
    $loan = defaultedLoan();

    financeUser();
    test()->postJson("/api/v1/loans/{$loan->id}/write-off", [
        'reason' => 'Borrower untraceable after twelve months of recovery attempts.',
    ])->assertCreated();

    $writeOff = WriteOff::query()->where('loan_id', $loan->getKey())->sole();
    $principal = $writeOff->principalMoney();

    test()->postJson("/api/v1/loans/{$loan->id}/recovery", ['amount' => '10000.00'])->assertCreated();

    expect($writeOff->fresh()->outstanding()->toDecimalString())
        ->toBe($principal->subtract(App\Support\Money::of('10000.00'))->toDecimalString());
});

it('refuses a recovery on a loan that was never written off', function (): void {
    $loan = defaultedLoan();

    financeUser();

    // Money arriving on a loan still on the book is an ordinary repayment.
    test()->postJson("/api/v1/loans/{$loan->id}/recovery", ['amount' => '10000.00'])
        ->assertStatus(409);
});

it('keeps the books balanced through write-off and recovery', function (): void {
    $loan = defaultedLoan();

    financeUser();
    test()->postJson("/api/v1/loans/{$loan->id}/write-off", [
        'reason' => 'Borrower untraceable after twelve months of recovery attempts.',
    ])->assertCreated();
    test()->postJson("/api/v1/loans/{$loan->id}/recovery", ['amount' => '25000.00'])->assertCreated();

    expect(app(TrialBalanceBuilder::class)->build()['balanced'])->toBeTrue();
});

it('reports the write-off register with its running totals', function (): void {
    $loan = defaultedLoan();

    financeUser();
    test()->postJson("/api/v1/loans/{$loan->id}/write-off", [
        'reason' => 'Borrower untraceable after twelve months of recovery attempts.',
    ])->assertCreated();
    test()->postJson("/api/v1/loans/{$loan->id}/recovery", ['amount' => '10000.00'])->assertCreated();

    $response = test()->getJson('/api/v1/write-offs')->assertOk();

    expect($response->json('meta.recovered'))->toBe('10000.00')
        ->and($response->json('data.0.recoveredToDate'))->toBe('10000.00');
});
