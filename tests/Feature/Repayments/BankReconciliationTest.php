<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Ledger\Services\TrialBalanceBuilder;
use App\Domain\Repayments\Enums\PaymentStatus;
use App\Models\BankAccount;
use App\Models\CashDeposit;
use App\Models\JournalEntry;
use App\Models\Payment;

/**
 * Bank reconciliation — §15.3, and the gap that made `confirmed` unreachable.
 *
 * Before this shipped, nothing in the system could move a cash payment out of
 * `pending_verification`, so no payment had ever reached `confirmed` — which is
 * what forced OSC-7's ledger-anchored definition of "collected".
 */
beforeEach(function (): void {
    seedLedgerFoundation();
});

/** A teller takes cash over the counter, leaving it pending verification. */
function cashPaymentOnActiveLoan(string $amount = '10000.00'): Payment
{
    $loan = activeLoan();

    officerAt($loan->branch->name, RoleName::Teller);

    $id = test()->postJson('/api/v1/payments/cash', [
        'loanId' => $loan->getKey(),
        'amount' => $amount,
    ])->assertCreated()->json('data.id');

    return Payment::query()->findOrFail($id);
}

it('leaves a teller cash payment pending verification', function (): void {
    $payment = cashPaymentOnActiveLoan();

    // §7: teller cash-in-hand and bank-confirmed cash are two different trust
    // states, and only a reconciled deposit crosses between them.
    expect($payment->status)->toBe(PaymentStatus::PendingVerification)
        ->and($payment->confirmed_at)->toBeNull();
});

it('offers unbanked cash payments to the teller', function (): void {
    $payment = cashPaymentOnActiveLoan();

    $response = test()->getJson('/api/v1/cash-deposits/unbanked')->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())
        ->toContain((string) $payment->getKey());
});

it('posts nothing when a teller banks cash', function (): void {
    $payment = cashPaymentOnActiveLoan();
    $bank = BankAccount::query()->firstOrFail();

    $before = JournalEntry::query()->count();

    test()->postJson('/api/v1/cash-deposits', [
        'branch_id' => $payment->branch_id,
        'bank_account_id' => $bank->getKey(),
        'amount' => $payment->amount,
        'payment_ids' => [$payment->getKey()],
    ])->assertCreated()->assertJsonPath('data.status', 'pending');

    /*
     * Carrying cash to the bank does not move it between accounts until the
     * bank confirms receipt. Posting here would record money as banked on a
     * teller's say-so — exactly the fraud the two trust states prevent.
     */
    expect(JournalEntry::query()->count())->toBe($before);
});

it('confirms the payment and moves the money on reconciliation', function (): void {
    $payment = cashPaymentOnActiveLoan();
    $bank = BankAccount::query()->firstOrFail();
    $branch = $payment->branch;

    $depositId = test()->postJson('/api/v1/cash-deposits', [
        'branch_id' => $payment->branch_id,
        'bank_account_id' => $bank->getKey(),
        'amount' => $payment->amount,
        'payment_ids' => [$payment->getKey()],
    ])->assertCreated()->json('data.id');

    $accounts = app(AccountResolver::class);
    $tellerCashId = (int) $accounts->tellerCash($branch)->getKey();

    $beforeTill = collect(app(TrialBalanceBuilder::class)->build()['rows'])
        ->firstWhere('code', $accounts->tellerCash($branch)->code)['balance'];

    officerAt('Head Office', RoleName::Finance);
    test()->postJson("/api/v1/cash-deposits/{$depositId}/reconcile")
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed');

    // The transition nothing in the system could previously make.
    expect($payment->fresh()->status)->toBe(PaymentStatus::Confirmed)
        ->and($payment->fresh()->confirmed_at)->not->toBeNull();

    $deposit = CashDeposit::query()->findOrFail($depositId);
    $entry = JournalEntry::query()->with('lines')->findOrFail($deposit->journal_entry_id);

    $bankLine = $entry->lines->firstWhere('account_id', $bank->chart_account_id);
    $tillLine = $entry->lines->firstWhere('account_id', $tellerCashId);

    // §7: Dr Bank · Cr Teller Cash. The till gives it up, the bank takes it on.
    expect($bankLine->debit_amount)->toBe($payment->amount)
        ->and($tillLine->credit_amount)->toBe($payment->amount)
        ->and($entry->isBalanced())->toBeTrue();

    $afterTill = collect(app(TrialBalanceBuilder::class)->build()['rows'])
        ->firstWhere('code', $accounts->tellerCash($branch)->code)['balance'];

    expect(bccomp($beforeTill, $afterTill, 2))->toBe(1);
});

it('recognises no income on reconciliation', function (): void {
    $payment = cashPaymentOnActiveLoan();
    $bank = BankAccount::query()->firstOrFail();

    $accounts = app(AccountResolver::class);

    $interestBefore = collect(app(TrialBalanceBuilder::class)->build()['rows'])
        ->firstWhere('code', SystemAccountCode::InterestIncome->value)['balance'];

    $depositId = test()->postJson('/api/v1/cash-deposits', [
        'branch_id' => $payment->branch_id,
        'bank_account_id' => $bank->getKey(),
        'amount' => $payment->amount,
        'payment_ids' => [$payment->getKey()],
    ])->assertCreated()->json('data.id');

    officerAt('Head Office', RoleName::Finance);
    test()->postJson("/api/v1/cash-deposits/{$depositId}/reconcile")->assertOk();

    /*
     * Income was recognised when the teller took the money. Reconciliation only
     * relocates it — treating this as the point of recognition would delay
     * every branch's revenue by however long its deposits take to clear.
     */
    expect(collect(app(TrialBalanceBuilder::class)->build()['rows'])
        ->firstWhere('code', SystemAccountCode::InterestIncome->value)['balance'])
        ->toBe($interestBefore);

    expect(app(TrialBalanceBuilder::class)->build()['balanced'])->toBeTrue();
});

it('refuses a deposit whose payments do not sum to the amount banked', function (): void {
    $payment = cashPaymentOnActiveLoan('10000.00');
    $bank = BankAccount::query()->firstOrFail();

    // The teller declares 10,000 of payments but banks 9,000.
    $depositId = test()->postJson('/api/v1/cash-deposits', [
        'branch_id' => $payment->branch_id,
        'bank_account_id' => $bank->getKey(),
        'amount' => '9000.00',
        'payment_ids' => [$payment->getKey()],
    ])->assertCreated()->json('data.id');

    officerAt('Head Office', RoleName::Finance);

    // §7's "amount mismatch → investigation", refused rather than reconciled
    // optimistically and queried later.
    test()->postJson("/api/v1/cash-deposits/{$depositId}/reconcile")->assertStatus(409);

    expect($payment->fresh()->status)->toBe(PaymentStatus::PendingVerification);
});

it('refuses to reconcile the same deposit twice', function (): void {
    $payment = cashPaymentOnActiveLoan();
    $bank = BankAccount::query()->firstOrFail();

    $depositId = test()->postJson('/api/v1/cash-deposits', [
        'branch_id' => $payment->branch_id,
        'bank_account_id' => $bank->getKey(),
        'amount' => $payment->amount,
        'payment_ids' => [$payment->getKey()],
    ])->assertCreated()->json('data.id');

    officerAt('Head Office', RoleName::Finance);
    test()->postJson("/api/v1/cash-deposits/{$depositId}/reconcile")->assertOk();

    // A second confirmation would move money out of a till that no longer
    // holds it.
    test()->postJson("/api/v1/cash-deposits/{$depositId}/reconcile")->assertStatus(409);

    expect(JournalEntry::query()->where('source_id', $depositId)
        ->where('source_type', 'transfer')->count())->toBe(1);
});

it('does not let a teller reconcile their own deposit', function (): void {
    $payment = cashPaymentOnActiveLoan();
    $bank = BankAccount::query()->firstOrFail();

    $depositId = test()->postJson('/api/v1/cash-deposits', [
        'branch_id' => $payment->branch_id,
        'bank_account_id' => $bank->getKey(),
        'amount' => $payment->amount,
        'payment_ids' => [$payment->getKey()],
    ])->assertCreated()->json('data.id');

    // §14: `repayments.cash_entry` records a deposit, `repayments.reconcile`
    // confirms one, and no role holds both by default.
    test()->postJson("/api/v1/cash-deposits/{$depositId}/reconcile")->assertForbidden();
});

it('excludes a banked payment from the unbanked list', function (): void {
    $payment = cashPaymentOnActiveLoan();
    $bank = BankAccount::query()->firstOrFail();

    test()->postJson('/api/v1/cash-deposits', [
        'branch_id' => $payment->branch_id,
        'bank_account_id' => $bank->getKey(),
        'amount' => $payment->amount,
        'payment_ids' => [$payment->getKey()],
    ])->assertCreated();

    // Otherwise a teller could bank the same takings twice.
    expect(collect(test()->getJson('/api/v1/cash-deposits/unbanked')->json('data'))->pluck('id')->all())
        ->not->toContain((string) $payment->getKey());
});

it('refuses a deposit that names no payments', function (): void {
    $payment = cashPaymentOnActiveLoan();
    $bank = BankAccount::query()->firstOrFail();

    test()->postJson('/api/v1/cash-deposits', [
        'branch_id' => $payment->branch_id,
        'bank_account_id' => $bank->getKey(),
        'amount' => $payment->amount,
        'payment_ids' => [],
    ])->assertStatus(422);
});
