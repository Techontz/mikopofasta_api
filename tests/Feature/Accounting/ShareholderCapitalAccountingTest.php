<?php

namespace Tests\Feature\Accounting;

use App\Enums\Account;
use App\Enums\LoanStatus;
use App\Models\AccountingPeriod;
use App\Models\BankAccount;
use App\Models\BankTransfer;
use App\Models\BranchPeriodResult;
use App\Models\Capital;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Loan;
use App\Models\LoanCategory;
use App\Models\LoanDisbursement;
use App\Models\Penalty;
use App\Models\ShareHolder;
use App\Services\Ledger;
use App\Services\LoanService;
use App\Services\ShareholderOwnership;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\UploadsLoanAgreement;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

/**
 * Shareholder capital → ownership → company Cash/Bank → loan disbursement accounting (the owner's TEST 1–8 and the
 * supporting rules): ownership comes only from the share register (shares ÷ total issued shares — the owner superseded
 * the earlier contribution-ratio rule); contributions stay financial records posted Dr Cash/Bank / Cr Capital; internal
 * fund moves are balanced transfers; a loan is disbursed once from the chosen source, Dr Loan Receivable / Cr source.
 */
class ShareholderCapitalAccountingTest extends TestCase
{
    use RefreshDatabase;
    use UploadsLoanAgreement;
    use UsesSecondApprover;

    private Employee $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['integrations.vodacom.driver' => 'test', 'integrations.bank_mandate.driver' => 'test', 'integrations.vodacom.test_outcome' => 'success']);
        $this->admin = $this->signInAdmin();
    }

    public function test_1_ownership_comes_from_the_share_register_and_contributions_stay_contribution_totals(): void
    {
        $a = $this->holder('ALPHA');
        $b = $this->holder('BETA');
        $first = $this->contribute($a, 10000000)->json('data.id');
        $second = $this->contribute($b, 30000000)->json('data.id');

        $this->assertOwnership([$a->id => [10000000, 0, 0], $b->id => [30000000, 0, 0]], 'contributions alone give no ownership');

        $this->allocate([[$a, 250, $first], [$b, 750, $second]]);

        $this->assertOwnership([$a->id => [10000000, 250, 25], $b->id => [30000000, 750, 75]]);
    }

    public function test_2_contribution_totals_sum_every_historical_contribution_while_ownership_follows_shares(): void
    {
        $a = $this->holder('ALPHA');
        $b = $this->holder('BETA');
        $this->contribute($a, 10000000);
        $this->contribute($a, 20000000, 'BANK', $this->bank('NMB'));
        $this->contribute($b, 70000000);
        $this->allocate([[$a, 300], [$b, 700]]);

        $this->assertOwnership([$a->id => [30000000, 300, 30], $b->id => [70000000, 700, 70]]);
        $this->assertSame(2, Capital::where('share_holder_id', $a->id)->count(), 'each contribution is its own row');
    }

    public function test_3_spending_transferring_or_lending_company_money_does_not_change_ownership(): void
    {
        $a = $this->holder('ALPHA');
        $b = $this->holder('BETA');
        $bank = $this->bank('CRDB');
        $first = $this->contribute($a, 50000000)->json('data.id');
        $second = $this->contribute($b, 50000000)->json('data.id');
        $this->allocate([[$a, 500, $first], [$b, 500, $second]]);
        $this->assertOwnership([$a->id => [50000000, 500, 50], $b->id => [50000000, 500, 50]]);

        $toBank = $this->postJson('/api/v1/bank/company-transfers', ['direction' => 'company_to_bank', 'bank_account_id' => $bank->id, 'amount' => 30000000])->assertCreated()->json('data.id');
        $this->approveAsSecondUser($this->admin, "/api/v1/bank/transfers/{$toBank}/approve");
        $float = $this->postJson('/api/v1/capital/floats', ['amount' => 20000000, 'from_account' => Account::Company->value])->assertCreated()->json('data.id');
        $this->approveAsSecondUser($this->admin, "/api/v1/capital/floats/{$float}/approve");
        app(Ledger::class)->journal($this->admin->company_id, 'OFFICE RENT', [
            ['account' => Account::OperatingExpense, 'debit' => 10000000],
            ['account' => Account::Company, 'credit' => 10000000],
        ]);
        $this->activeLoanFrom(['source_account' => 'cash'], 1000000);

        $ledger = app(Ledger::class);
        $this->assertSame(40000000.0, $ledger->balance($this->admin->company_id, Account::Company));
        $this->assertSame(30000000.0, $ledger->balance($this->admin->company_id, Account::Bank, bankAccount: $bank));
        $this->assertSame(19000000.0, $ledger->balance($this->admin->company_id, Account::Principal));
        $this->assertOwnership([$a->id => [50000000, 500, 50], $b->id => [50000000, 500, 50]]);
        $this->getJson('/api/v1/capital/capitals')->assertOk()
            ->assertJsonPath('data.share_holder_capital', 100000000)
            ->assertJsonPath('data.company_cash_balance', 40000000)
            ->assertJsonPath('data.bank_balance_total', 30000000);
    }

    public function test_4_contribution_posts_debit_receiving_cash_or_bank_and_credit_capital(): void
    {
        $a = $this->holder('ALPHA');
        $bank = $this->bank('NMB');

        $cash = Capital::findOrFail($this->contribute($a, 50000000)->json('data.id'));
        $this->assertEntry($cash->journal_entry_id, [
            [Account::Company, null, 50000000, 0],
            [Account::Capital, null, 0, 50000000],
        ]);

        $banked = Capital::findOrFail($this->contribute($a, 50000000, 'BANK', $bank)->json('data.id'));
        $this->assertEntry($banked->journal_entry_id, [
            [Account::Bank, $bank->id, 50000000, 0],
            [Account::Capital, null, 0, 50000000],
        ]);

        $this->assertSame(['company_cash', null, $this->admin->id], [$cash->receiving_account, $cash->bank_account_id, $cash->recorded_by]);
        $this->assertSame(['bank', $bank->id], [$banked->receiving_account, $banked->bank_account_id]);
        $this->assertNotNull($banked->contributed_at);
        $this->assertSame(0.0, $this->typeTotal('income'), 'capital is never revenue');
    }

    public function test_5_loan_disbursed_from_principal_debits_receivable_and_credits_principal(): void
    {
        $bank = $this->bank('NMB');
        $this->contribute($this->holder('ALPHA'), 5000000, 'BANK', $bank);
        $ledger = app(Ledger::class);
        $ledger->openingBalance($this->admin->company_id, Account::Principal, 3000000, 'FLOAT');

        $loan = $this->activeLoanFrom([], 1000000);

        $disbursement = LoanDisbursement::where('loan_id', $loan->id)->sole();
        $this->assertSame([LoanDisbursement::SUCCESS, 'cash', null], [$disbursement->status, $disbursement->source_account, $disbursement->source_bank_account_id]);
        $this->assertEntry($disbursement->journal_entry_id, [
            [Account::LoanReceivable, null, 1000000, 0],
            [Account::Principal, null, 0, 1000000],
        ]);
        $this->assertSame(5000000.0, $ledger->balance($loan->company_id, Account::Bank, bankAccount: $bank), 'company bank accounts are never a disbursement source');
        $this->assertSame(1000000.0, $ledger->balance($loan->company_id, Account::LoanReceivable, $loan->branch_id));
        $this->assertSame(2000000.0, $ledger->balance($loan->company_id, Account::Principal));
        $this->assertSame(0.0, $this->typeTotal('expense'));
        $this->assertSame(0.0, $this->typeTotal('income'));

        $this->getJson(route('api.v1.loans.show', $loan))->assertOk()
            ->assertJsonPath('data.disbursement_chain.customer.id', $loan->customer_id)
            ->assertJsonPath('data.disbursement_chain.loan.loan_number', $loan->loan_number)
            ->assertJsonPath('data.disbursement_chain.manager_approval.context.amount_approved', 1000000)
            ->assertJsonPath('data.disbursement_chain.disbursement.source_label', Account::Principal->label().' (HQ CASH)')
            ->assertJsonPath('data.disbursement_chain.disbursement.amount', 1000000)
            ->assertJsonPath('data.disbursement_chain.journal_entry.reference', $disbursement->journalEntry->reference)
            ->assertJsonPath('data.ledger.receivable_balance', 1000000);

        $sheet = $this->getJson('/api/v1/reports/financial/balance-sheet?branch_id=all')->assertOk()->json('data');
        $this->assertTrue($sheet['balanced']);
    }

    public function test_6_failed_disbursement_leaves_no_partial_financial_transaction(): void
    {
        app(Ledger::class)->openingBalance($this->admin->company_id, Account::Principal, 500000, 'FLOAT');
        $before = JournalEntry::count();

        $short = $this->loanAtFinance(1000000);
        $this->postJson(route('api.v1.loans.prepare-disbursement', $short))
            ->assertUnprocessable()->assertJsonValidationErrors('source_account');
        $this->assertSame(LoanStatus::PendingFinance, $short->fresh()->status);
        $this->assertSame(0, LoanDisbursement::where('loan_id', $short->id)->count());
        $short->update(['status' => LoanStatus::Cancelled]);

        config(['integrations.vodacom.test_outcome' => 'failed']);
        $failed = $this->loanAtFinance(400000);
        $this->postJson(route('api.v1.loans.prepare-disbursement', $failed))->assertOk();
        $this->postJson(route('api.v1.loans.disburse', $failed))->assertUnprocessable();
        $this->assertSame(LoanStatus::DisbursementFailed, $failed->fresh()->status);
        $failed->update(['status' => LoanStatus::Cancelled]);

        config(['integrations.vodacom.test_outcome' => 'success']);
        $locked = $this->loanAtFinance(400000);
        $this->postJson(route('api.v1.loans.prepare-disbursement', $locked))->assertOk();
        AccountingPeriod::create(['company_id' => $this->admin->company_id, 'period_start' => today()->startOfMonth()->toDateString(), 'period_end' => today()->endOfMonth()->toDateString(), 'status' => 'closed']);
        $this->postJson(route('api.v1.loans.disburse', $locked))->assertUnprocessable()->assertJsonValidationErrors('entry_date');

        $this->assertSame($before, JournalEntry::count(), 'no journal entry from any failed attempt');
        foreach ([$short, $failed, $locked] as $loan) {
            $this->assertSame(0, $loan->transactions()->count());
            $this->assertSame(0, $loan->schedules()->count());
            $this->assertNull($loan->fresh()->disbursed_at);
        }
        $this->assertSame(LoanStatus::AwaitingDisbursement, $locked->fresh()->status);
        $this->assertNotSame(LoanDisbursement::SUCCESS, $locked->latestDisbursement()->value('status'));
        $this->assertSame(0, LoanDisbursement::whereNotNull('journal_entry_id')->count());
        $this->assertSame(500000.0, app(Ledger::class)->balance($this->admin->company_id, Account::Principal));
    }

    public function test_7_retries_and_duplicate_requests_never_post_a_second_journal(): void
    {
        $holder = $this->holder('ALPHA');
        $bank = $this->bank('NMB');

        $payload = ['share_id' => $holder->id, 'amount' => 3000000, 'pay_method' => 'BANK', 'bank_account_id' => $bank->id, 'idempotency_key' => 'capital-key-1'];
        $id = $this->postJson('/api/v1/capital/capitals', $payload)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/capital/capitals', $payload)->assertOk()->assertJsonPath('message', 'Capital was already recorded');
        $approver = $this->secondApprover($this->admin);
        $this->asApprover($this->admin, fn () => $this->postJson("/api/v1/capital/capitals/{$id}/approve")->assertOk(), $approver);
        $this->asApprover($this->admin, fn () => $this->postJson("/api/v1/capital/capitals/{$id}/approve")->assertUnprocessable(), $approver);
        $this->postJson('/api/v1/capital/capitals', ['amount' => 1] + $payload)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->assertSame(1, Capital::count());
        $this->assertSame(1, JournalEntry::where('source_type', (new Capital)->getMorphClass())->count());

        $transfer = ['direction' => 'bank_to_company', 'bank_account_id' => $bank->id, 'amount' => 1000000, 'idempotency_key' => 'transfer-key-1'];
        $transferId = $this->postJson('/api/v1/bank/company-transfers', $transfer)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/bank/company-transfers', $transfer)->assertOk();
        $this->asApprover($this->admin, fn () => $this->postJson("/api/v1/bank/transfers/{$transferId}/approve")->assertOk(), $approver);
        $this->asApprover($this->admin, fn () => $this->postJson("/api/v1/bank/transfers/{$transferId}/approve")->assertUnprocessable(), $approver);
        $this->assertSame(1000000.0, app(Ledger::class)->balance($this->admin->company_id, Account::Company));

        config(['integrations.vodacom.test_outcome' => 'failed']);
        $loan = $this->loanAtFinance(500000);
        app(Ledger::class)->openingBalance($this->admin->company_id, Account::Principal, 500000, 'FLOAT');
        $this->postJson(route('api.v1.loans.prepare-disbursement', $loan), ['source_account' => 'cash'])->assertOk();
        $this->postJson(route('api.v1.loans.disburse', $loan))->assertUnprocessable();

        config(['integrations.vodacom.test_outcome' => 'callback', 'integrations.vodacom.callback_secret' => 'secret']);
        $this->postJson(route('api.v1.loans.retry-disbursement', $loan))->assertOk();
        $this->postJson(route('api.v1.loans.disburse', $loan))->assertUnprocessable();
        $retry = $loan->latestDisbursement()->firstOrFail();
        $this->assertSame([2, 'cash', null], [$retry->attempt, $retry->source_account, $retry->source_bank_account_id], 'a retry pays from the PRINCIPAL A/C');

        $body = json_encode(['batch_id' => $retry->batch_id, 'status' => 'SUCCESS', 'transaction_id' => 'MP777']);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, 'secret')];
        $this->call('POST', '/api/webhooks/vodacom/disbursement-status', [], [], [], $headers, $body)->assertOk()->assertJsonPath('status', 'PROCESSED');
        $this->call('POST', '/api/webhooks/vodacom/disbursement-status', [], [], [], $headers, $body)->assertOk()->assertJsonPath('status', 'ALREADY_PROCESSED');
        $this->postJson(route('api.v1.loans.disburse', $loan))->assertUnprocessable();
        $this->postJson(route('api.v1.loans.retry-disbursement', $loan))->assertUnprocessable();

        $first = $loan->disbursements()->orderBy('attempt')->firstOrFail();
        $body = json_encode(['batch_id' => $first->batch_id, 'status' => 'SUCCESS', 'transaction_id' => 'MP778']);
        $this->call('POST', '/api/webhooks/vodacom/disbursement-status', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, 'secret')], $body)
            ->assertOk()->assertJsonPath('status', 'ALREADY_PROCESSED');

        $this->assertSame(LoanStatus::Active, $loan->fresh()->status);
        $this->assertSame(1, JournalEntry::where('source_type', $loan->getMorphClass())->where('source_id', $loan->id)->count());
        $this->assertSame(1, $loan->transactions()->where('type', 'withdrawal')->count());
        $this->assertSame(1, LoanDisbursement::where('loan_id', $loan->id)->where('status', LoanDisbursement::SUCCESS)->count());
        $this->assertSame(2000000.0, app(Ledger::class)->balance($this->admin->company_id, Account::Bank, bankAccount: $bank));
        $this->assertSame(0.0, app(Ledger::class)->balance($this->admin->company_id, Account::Principal));
    }

    public function test_8_repayment_is_allocated_principal_penalty_interest_and_stays_balanced(): void
    {
        $bank = $this->bank('NMB');
        $this->contribute($this->holder('ALPHA'), 2000000, 'BANK', $bank);
        app(Ledger::class)->openingBalance($this->admin->company_id, Account::Principal, 100000, 'FLOAT');
        $loan = $this->activeLoanFrom(['source_account' => 'cash'], 100000);
        Penalty::create(['company_id' => $loan->company_id, 'branch_id' => $loan->branch_id, 'customer_id' => $loan->customer_id, 'loan_id' => $loan->id, 'amount' => 10000, 'penalty_date' => today()->subDay()]);

        $loans = app(LoanService::class);
        $first = $loans->deposit($loan->fresh(), 105000, CarbonImmutable::today());
        $this->assertSame([100000.0, 5000.0, 0.0], [(float) $first->principal, (float) $first->penalty, (float) $first->interest]);

        $second = $loans->deposit($loan->fresh(), 20000, CarbonImmutable::today());
        $this->assertSame([0.0, 5000.0, 15000.0], [(float) $second->principal, (float) $second->penalty, (float) $second->interest]);

        $ledger = app(Ledger::class);
        $this->assertSame(0.0, $ledger->balance($loan->company_id, Account::LoanReceivable, $loan->branch_id));
        $this->assertSame(10000.0, $ledger->balance($loan->company_id, Account::PenaltyIncome, $loan->branch_id));
        $reserve = round(15000 * (float) $loan->company->reserve_percent / 100, 2);
        $this->assertSame(15000.0 - $reserve, $ledger->balance($loan->company_id, Account::InterestIncome, $loan->branch_id), 'D6: interest income is net of the reserve');
        $this->assertSame($reserve, $ledger->balance($loan->company_id, Account::InterestReserve, $loan->branch_id));
        $this->assertSame(0.0, $this->getJson(route('api.v1.loans.show', $loan))->json('data.ledger.receivable_balance') + 0.0);

        foreach (JournalEntry::with('lines')->get() as $entry) {
            $this->assertEquals(round($entry->lines->sum('debit'), 2), round($entry->lines->sum('credit'), 2), "{$entry->description} is balanced");
        }
        $this->assertEquals(round(JournalLine::sum('debit'), 2), round(JournalLine::sum('credit'), 2));
        $this->assertTrue($this->getJson('/api/v1/reports/financial/balance-sheet?branch_id=all')->json('data.balanced'));
    }

    public function test_loans_are_disbursed_from_the_principal_account_only(): void
    {
        $bank = $this->bank('NMB');
        $this->contribute($this->holder('ALPHA'), 2000000, 'BANK', $bank);
        $ledger = app(Ledger::class);
        $ledger->openingBalance($this->admin->company_id, Account::Principal, 300000, 'FLOAT');
        $loan = $this->loanAtFinance(500000, feeDeducted: true);

        $this->getJson(route('api.v1.loans.disbursement-sources', $loan))->assertOk()
            ->assertJsonPath('data.cash.balance', 300000)
            ->assertJsonPath('data.cash.required', 500000)
            ->assertJsonMissingPath('data.banks');

        $this->postJson(route('api.v1.loans.prepare-disbursement', $loan), ['source_account' => 'bank', 'source_bank_account_id' => $bank->id])
            ->assertUnprocessable()->assertJsonValidationErrors('source_account');
        $this->postJson(route('api.v1.loans.prepare-disbursement', $loan))->assertUnprocessable()->assertJsonValidationErrors('source_account');

        $ledger->openingBalance($this->admin->company_id, Account::Principal, 200000, 'FLOAT');
        $this->postJson(route('api.v1.loans.prepare-disbursement', $loan))->assertOk();
        $this->postJson(route('api.v1.loans.disburse', $loan))->assertOk();

        $this->assertSame(['cash', null], [$loan->latestDisbursement()->value('source_account'), $loan->latestDisbursement()->value('source_bank_account_id')]);
        $this->assertSame(2000000.0, $ledger->balance($this->admin->company_id, Account::Bank, bankAccount: $bank), 'company bank accounts are never a disbursement source');
        $this->assertSame(0.0, $ledger->balance($this->admin->company_id, Account::Principal));
    }

    public function test_shareholder_without_shares_owns_nothing(): void
    {
        $a = $this->holder('ALPHA');
        $b = $this->holder('BETA');

        $this->assertOwnership([$a->id => [0, 0, 0], $b->id => [0, 0, 0]]);

        $contribution = $this->contribute($a, 1000000)->json('data.id');
        $this->assertOwnership([$a->id => [1000000, 0, 0], $b->id => [0, 0, 0]], 'a contribution is not ownership');

        $this->allocate([[$a, 1000, $contribution]]);
        app(Ledger::class)->openingBalance($this->admin->company_id, Account::Bank, 9000000, bankAccount: $this->bank('NMB'));

        $this->assertOwnership([$a->id => [1000000, 1000, 100], $b->id => [0, 0, 0]]);
        $this->getJson("/api/v1/capital/share-holders/{$b->id}")->assertOk()->assertJsonPath('data.total_contributed', 0)->assertJsonPath('data.shares', 0)->assertJsonPath('data.ownership_percent', 0);
    }

    public function test_contribution_history_keeps_every_contribution_with_its_trace(): void
    {
        $a = $this->holder('ALPHA');
        $bank = $this->bank('NMB');
        $this->contribute($a, 1000000, 'CASH', null, ['recept' => 'RC-1']);
        $this->contribute($a, 2500000, 'BANK', $bank, ['recept' => 'RC-2', 'chaque_no' => 'CHQ-9', 'contributed_at' => today()->subDays(3)->setTime(9, 30)->toDateTimeString()]);
        $beta = $this->holder('BETA');
        $this->contribute($beta, 1500000);
        $this->allocate([[$a, 700], [$beta, 300]]);

        $history = $this->getJson("/api/v1/capital/share-holders/{$a->id}/contributions")->assertOk()
            ->assertJsonPath('data.total_contributed', 3500000)
            ->assertJsonPath('data.shares', 700)
            ->assertJsonPath('data.ownership_percent', 70)
            ->assertJsonCount(2, 'data.contributions')
            ->json('data.contributions');

        $this->assertSame([1000000, 'CASH', 'COMPANY ACCOUNT', 'RC-1'], [(int) $history[0]['amount'], $history[0]['pay_method'], $history[0]['receiving_account_label'], $history[0]['receipt_number']]);
        $this->assertSame(['BANK - NMB', 'CHQ-9', today()->subDays(3)->setTime(9, 30)->toDateTimeString()], [$history[1]['receiving_account_label'], $history[1]['cheque_number'], $history[1]['contributed_at']]);
        foreach ($history as $row) {
            $this->assertNotNull($row['journal_reference']);
            $this->assertSame($this->admin->full_name, $row['recorded_by']);
        }

        $this->contribute($a, 500000);
        $this->assertSame([1000000.0, 2500000.0, 500000.0], Capital::where('share_holder_id', $a->id)->orderBy('id')->pluck('amount')->map(fn ($amount): float => (float) $amount)->all(), 'earlier contributions are never overwritten');
    }

    public function test_cash_bank_transfers_are_balanced_ledger_transfers_in_both_directions(): void
    {
        $bank = $this->bank('NMB');
        $this->contribute($this->holder('ALPHA'), 10000000);
        $ledger = app(Ledger::class);

        $toBank = $this->postJson('/api/v1/bank/company-transfers', ['direction' => 'company_to_bank', 'bank_account_id' => $bank->id, 'amount' => 6000000, 'reference' => 'DEP-1'])
            ->assertCreated()->json('data');
        $this->approveAsSecondUser($this->admin, "/api/v1/bank/transfers/{$toBank['id']}/approve");
        $back = $this->postJson('/api/v1/bank/company-transfers', ['direction' => 'bank_to_company', 'bank_account_id' => $bank->id, 'amount' => 2000000])->assertCreated()->json('data.id');
        $this->approveAsSecondUser($this->admin, "/api/v1/bank/transfers/{$back}/approve");
        $tooMuch = $this->postJson('/api/v1/bank/company-transfers', ['direction' => 'bank_to_company', 'bank_account_id' => $bank->id, 'amount' => 4000001])->assertCreated()->json('data.id');
        $this->asApprover($this->admin, fn () => $this->postJson("/api/v1/bank/transfers/{$tooMuch}/approve")->assertUnprocessable()->assertJsonValidationErrors('amount'));
        $this->postJson("/api/v1/bank/transfers/{$tooMuch}/reject", ['reason' => 'Insufficient bank balance'])->assertOk();

        $this->assertSame(6000000.0, $ledger->balance($this->admin->company_id, Account::Company));
        $this->assertSame(4000000.0, $ledger->balance($this->admin->company_id, Account::Bank, bankAccount: $bank));
        $this->assertSame(10000000.0, $ledger->balance($this->admin->company_id, Account::Company) + $ledger->balance($this->admin->company_id, Account::Bank, bankAccount: $bank), 'company total is unchanged');

        $transfer = BankTransfer::findOrFail($toBank['id']);
        $this->assertEntry($transfer->journal_entry_id, [
            [Account::Bank, $bank->id, 6000000, 0],
            [Account::Company, null, 0, 6000000],
        ]);
        $this->getJson('/api/v1/bank/company-transfers')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.status', 'rejected')->assertJsonPath('data.2.reference', 'DEP-1');
        $this->getJson('/api/v1/capital/position')->assertOk()
            ->assertJsonPath('data.shareholder_contributions.total', 10000000)
            ->assertJsonPath('data.balances.company_cash', 6000000)
            ->assertJsonPath('data.balances.bank_total', 4000000);
    }

    public function test_dividends_are_split_by_share_register_ownership_only(): void
    {
        $a = $this->holder('ALPHA');
        $b = $this->holder('BETA');
        $first = $this->contribute($a, 10000000)->json('data.id');
        $second = $this->contribute($b, 30000000)->json('data.id');
        $this->allocate([[$a, 250, $first], [$b, 750, $second]]);
        app(Ledger::class)->openingBalance($this->admin->company_id, Account::Bank, 60000000, bankAccount: $this->bank('NMB'));
        app(Ledger::class)->journal($this->admin->company_id, 'MONTH END PROFIT', [
            ['account' => Account::Interest, 'debit' => 1000000, 'branch' => $this->admin->branch_id],
            ['account' => Account::RetainedProfit, 'credit' => 1000000, 'branch' => $this->admin->branch_id],
        ]);
        $month = now()->subMonthNoOverflow()->startOfMonth();
        $period = AccountingPeriod::create(['company_id' => $this->admin->company_id, 'period_start' => $month->toDateString(), 'period_end' => $month->endOfMonth()->toDateString(), 'status' => AccountingPeriod::STATUS_CLOSED, 'closed_at' => now(), 'commission_calculated_at' => now()]);
        BranchPeriodResult::create(['accounting_period_id' => $period->id, 'branch_id' => $this->admin->branch_id, 'gross_profit' => 1000000, 'net_profit' => 1000000, 'distributable_profit' => 1000000, 'commission_eligible' => true]);

        $this->getJson('/api/v1/capital/dividends/preview?period='.$month->format('Y-m'))->assertOk()
            ->assertJsonPath('data.rows.0.contribution_total', 10000000)
            ->assertJsonPath('data.rows.0.shares', 250)
            ->assertJsonPath('data.rows.0.ownership_percent', 25)
            ->assertJsonPath('data.rows.1.ownership_percent', 75);

        $requestId = $this->postJson('/api/v1/capital/dividends', ['period' => now()->subMonthNoOverflow()->format('Y-m')])->assertCreated()->json('data.id');
        $declarationId = $this->approveAsSecondUser($this->admin, "/api/v1/capital/dividends/requests/{$requestId}/approve")->json('data.id');
        $this->getJson("/api/v1/capital/dividends/{$declarationId}/allocations")->assertOk()
            ->assertJsonPath('data.0.ownership_percent', 25)
            ->assertJsonPath('data.0.entitlement', 75000)
            ->assertJsonPath('data.1.entitlement', 225000);

        $this->assertOwnership([$a->id => [10000000, 250, 25], $b->id => [30000000, 750, 75]], 'the reinvested 70% (REINVESTED PROFIT, D4) is neither a contribution nor shares');
    }

    private function holder(string $firstName): ShareHolder
    {
        return ShareHolder::create(['company_id' => $this->admin->company_id, 'first_name' => $firstName, 'last_name' => 'HOLDER', 'mobile' => '0777', 'email' => strtolower($firstName).'@example.com', 'date_of_birth' => '1990-01-01']);
    }

    private function bank(string $name): BankAccount
    {
        return BankAccount::create(['company_id' => $this->admin->company_id, 'name' => $name]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function contribute(ShareHolder $holder, float $amount, string $method = 'CASH', ?BankAccount $bank = null, array $extra = []): TestResponse
    {
        $id = $this->postJson('/api/v1/capital/capitals', $extra + ['share_id' => $holder->id, 'amount' => $amount, 'pay_method' => $method, 'bank_account_id' => $bank?->id])->assertCreated()->json('data.id');

        return $this->approveAsSecondUser($this->admin, "/api/v1/capital/capitals/{$id}/approve");
    }

    /**
     * Set up the share register with an initial allocation; a shareholder's line links their recorded contribution when
     * its id is given (no second journal) and is otherwise allocated without cash.
     *
     * @param  list<array{0: ShareHolder, 1: int, 2?: int}>  $allocations  [shareholder, shares, contribution id]
     */
    private function allocate(array $allocations): void
    {
        $this->postJson('/api/v1/shares/structure', [
            'capital_basis' => array_sum(array_column($allocations, 1)) * 10000,
            'total_shares' => array_sum(array_column($allocations, 1)),
            'established_on' => today()->toDateString(),
            'allocations' => array_map(fn (array $line): array => [
                'share_holder_id' => $line[0]->id,
                'shares' => $line[1],
                'treatment' => isset($line[2]) ? 'linked_contribution' : 'no_cash',
                'capital_id' => $line[2] ?? null,
            ], $allocations),
        ])->assertCreated();
    }

    /**
     * @param  array<int, array{0: float|int, 1: int, 2: float|int}>  $expected  share holder id => [total contributed, shares, ownership %]
     */
    private function assertOwnership(array $expected, string $message = ''): void
    {
        $service = app(ShareholderOwnership::class)->summary($this->admin->company_id)->keyBy(fn (array $row): int => $row['share_holder']->id);
        $api = collect($this->getJson('/api/v1/capital/share-holders')->assertOk()->json('data'))->keyBy('id');

        foreach ($expected as $id => [$total, $shares, $percent]) {
            $this->assertEquals($total, $service[$id]['total_contributed'], $message);
            $this->assertSame($shares, $service[$id]['shares'], $message);
            $this->assertEquals($percent, $service[$id]['ownership_percent'], $message);
            $this->assertEquals($total, $api[$id]['total_contributed'], $message);
            $this->assertSame($shares, $api[$id]['shares'], $message);
            $this->assertEquals($percent, $api[$id]['ownership_percent'], $message);
        }
    }

    /**
     * @param  list<array{0: Account, 1: int|null, 2: float|int, 3: float|int}>  $expected  [account, bank account id, debit, credit]
     */
    private function assertEntry(?int $entryId, array $expected): void
    {
        $this->assertNotNull($entryId);
        $lines = JournalLine::with('account')->where('journal_entry_id', $entryId)->orderBy('id')->get();

        $this->assertCount(count($expected), $lines);
        foreach ($expected as $index => [$account, $bankId, $debit, $credit]) {
            $this->assertSame($account, $lines[$index]->account->key);
            $this->assertSame($bankId, $lines[$index]->account->bank_account_id);
            $this->assertEquals($debit, $lines[$index]->debit);
            $this->assertEquals($credit, $lines[$index]->credit);
        }
        $this->assertEquals($lines->sum('debit'), $lines->sum('credit'), 'debits equal credits');
    }

    private function typeTotal(string $type): float
    {
        return array_sum(array_map(
            fn (Account $account): float => app(Ledger::class)->balance($this->admin->company_id, $account, allBranches: true),
            array_filter(Account::cases(), fn (Account $account): bool => $account->type() === $type),
        ));
    }

    private function loanAtFinance(float $amount, bool $feeDeducted = false): Loan
    {
        // Rule 6 is covered by SegregationOfDutiesTest; this fixture drives every loan stage as one admin, so self-approval is granted explicitly.
        $this->grantSelfApproval($this->admin);
        $category = LoanCategory::factory()->create(['company_id' => $this->admin->company_id, 'insurance' => 0]);
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'phone' => '2557540'.random_int(10000, 99999), 'customer_category_id' => $category->customer_category_id]);
        $category->branches()->attach($this->admin->branch_id);

        $this->postJson(route('api.v1.loans.store'), [
            'customer_id' => $customer->id, 'category_id' => $category->id, 'how_loan' => $amount,
            'session' => 1, 'rate' => 'SIMPLE', 'fee_status' => $feeDeducted ? 'YES' : 'NO', 'reason' => 'BIASHARA',
        ])->assertCreated();
        $loan = Loan::latest('id')->firstOrFail();

        $this->postJson(route('api.v1.loans.approve-manager', $loan), ['loan_aprove' => $amount])->assertOk();
        $this->uploadAgreement($loan)->assertOk();
        $this->postJson(route('api.v1.loans.kyc-verify', $loan))->assertOk();
        $this->postJson(route('api.v1.loans.approve-credit', $loan))->assertOk();

        return $loan->fresh();
    }

    /**
     * @param  array{source_account: string, source_bank_account_id?: int}  $source
     */
    private function activeLoanFrom(array $source, float $amount): Loan
    {
        $loan = $this->loanAtFinance($amount);
        $this->postJson(route('api.v1.loans.prepare-disbursement', $loan), $source)->assertOk();
        $this->postJson(route('api.v1.loans.disburse', $loan))->assertOk()->assertJsonPath('message', 'Loan Disbursed successfully');

        return tap($loan->fresh(), fn (Loan $active) => $this->assertSame(LoanStatus::Active, $active->status));
    }
}
