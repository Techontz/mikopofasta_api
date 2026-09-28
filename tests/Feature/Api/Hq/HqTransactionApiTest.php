<?php

namespace Tests\Feature\Api\Hq;

use App\Enums\Account;
use App\Enums\HqFund;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Employee;
use App\Models\HqTransaction;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

class HqTransactionApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesSecondApprover;

    public function test_balances_list_the_hq_funds_finance_sees_behind_the_dashboard_card(): void
    {
        $admin = $this->signInAdmin();
        $ledger = app(Ledger::class);
        $ledger->openingBalance($admin->company_id, Account::Principal, 7184000);
        $ledger->openingBalance($admin->company_id, Account::HqInterest, 198190);
        // A claim row: listed, but never added to the total. Closed profit, then declared out of it exactly as
        // DividendService does it — DIVIDEND PAYABLE is credit-normal, so a declaration credits it.
        $ledger->journal($admin->company_id, 'MONTH END', [
            ['account' => Account::Company, 'debit' => 500000.0],
            ['account' => Account::RetainedProfit, 'credit' => 500000.0],
        ]);
        $ledger->journal($admin->company_id, 'DIVIDEND DECLARATION', [
            ['account' => Account::RetainedProfit, 'debit' => 500000.0],
            ['account' => Account::DividendPayable, 'credit' => 500000.0],
        ]);

        $response = $this->getJson('/api/v1/hq/balances')->assertOk()
            ->assertJsonCount(8, 'data')
            ->assertJsonPath('data.0.name', 'OPERATION PRINCIPAL')
            ->assertJsonPath('data.0.balance', 7184000)
            ->assertJsonPath('data.1.name', 'OPERATION INCOME')
            ->assertJsonPath('data.1.balance', 198190)
            ->assertJsonPath('data.7.name', 'DIVIDENDS')
            ->assertJsonPath('data.7.balance', 500000)
            ->assertJsonPath('data.7.in_total', false)
            ->assertJsonPath('total', 7382190);

        // The page and Finance's green card are the same figures. (The owners' card is the Investment instead,
        // so this is checked as Finance, who has no capital.view.)
        $this->actingAs($this->secondApprover($admin, 'finance'));
        $this->assertSame(
            collect($response->json('data'))->pluck('balance', 'name')->all(),
            $this->getJson('/api/v1/dashboard')->assertOk()->json('data.account_balances'),
        );
    }

    public function test_dropdowns_offer_finance_his_own_rows_and_the_shareholders_accounts(): void
    {
        $admin = $this->signInAdmin();
        app(Ledger::class)->openingBalance($admin->company_id, Account::HqInterest, 100000);
        // A shareholders' account named after an HQ row is the pair the form fills the To box with.
        $fundBank = BankAccount::create(['company_id' => $admin->company_id, 'name' => 'FUND ACCOUNT']);

        $this->getJson('/api/v1/hq/options/accounts?direction=from')->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.1.value', 'operation_income')
            ->assertJsonPath('data.1.label', 'OPERATION INCOME - 100,000')
            ->assertJsonPath('data.1.pairs_with', null)
            ->assertJsonPath('data.1.locked', false)
            ->assertJsonPath('data.2.value', 'fund')
            ->assertJsonPath('data.2.pairs_with', 'bank:'.$fundBank->id)
            // RESERVE and DIVIDEND are Finance's rows too, each fixed to the shareholders' account of the same name.
            ->assertJsonPath('data.3.value', 'reserve')
            ->assertJsonPath('data.3.label', 'RESERVE - 0')
            ->assertJsonPath('data.3.pairs_with', Account::InvestmentReserve->value)
            ->assertJsonPath('data.3.pairs_with_label', 'Reserve A/C')
            ->assertJsonPath('data.3.locked', true)
            ->assertJsonPath('data.4.value', 'dividend')
            ->assertJsonPath('data.4.pairs_with', Account::DividendPayable->value)
            ->assertJsonPath('data.4.locked', true);

        $destinations = $this->getJson('/api/v1/hq/options/accounts?direction=to')->assertOk()->json('data');
        $this->assertSame(['Company A/C', 'FUND ACCOUNT', 'Reserve A/C', 'Dividend A/C'], array_column($destinations, 'label'));
        // HQ never sees the Investment: the destinations are names, never balances.
        foreach ($destinations as $destination) {
            $this->assertStringNotContainsString(' - ', $destination['label']);
        }
    }

    public function test_transaction_is_requested_approved_and_listed(): void
    {
        $admin = $this->signInAdmin();
        $ledger = app(Ledger::class);
        $ledger->openingBalance($admin->company_id, Account::HqInterest, 100000);
        // Rule 6: an Admin requests, so the initiator is not the Super Admin (who approves their own items).
        $requester = $this->secondApprover($admin, 'admin');
        $this->actingAs($requester);

        $this->postJson('/api/v1/hq/transactions', [
            'from_account' => HqFund::OperationIncome->value, 'to_account' => Account::Company->value, 'amount' => 60000, 'charge' => 1000,
        ])->assertCreated()->assertJsonPath('message', 'Transaction Requested successfully');

        $transaction = HqTransaction::firstOrFail();
        $this->getJson('/api/v1/hq/transactions')->assertOk()
            ->assertJsonPath('data.0.from_account_label', 'OPERATION INCOME')
            ->assertJsonPath('data.0.to_account_label', 'Company A/C')
            ->assertJsonPath('total_charge', 1000);

        // Rule 6: the requester cannot approve; a second authorised user does.
        $this->postJson("/api/v1/hq/transactions/{$transaction->id}/approve")->assertForbidden();
        $approver = $this->secondApprover($admin);
        $this->asApprover($requester, fn () => $this->postJson("/api/v1/hq/transactions/{$transaction->id}/approve")->assertOk()->assertJsonPath('message', 'Transaction Approved successfully'), $approver);

        $transaction->refresh();
        $this->assertSame('approved', $transaction->status);
        $this->assertSame($approver->id, $transaction->approved_by);
        // The charge leaves the same row as the amount.
        $this->assertSame(39000.0, $ledger->balance($admin->company_id, Account::HqInterest));
        $this->assertSame(60000.0, $ledger->balance($admin->company_id, Account::Company));
        $this->assertSame(1000.0, $ledger->balance($admin->company_id, Account::BankCharges));

        $this->getJson('/api/v1/hq/transactions?status=approved&from='.today()->toDateString().'&to='.today()->toDateString())->assertJsonPath('data.0.approved_at', today()->toDateString());
        $this->getJson('/api/v1/hq/transactions?status=approved&from=2020-01-01&to=2020-01-02')->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/hq/transactions/{$transaction->id}/approve")->assertUnprocessable();
        $this->deleteJson("/api/v1/hq/transactions/{$transaction->id}")->assertUnprocessable();
    }

    public function test_a_pooled_row_is_drawn_from_each_account_in_proportion_and_a_bank_can_receive(): void
    {
        $admin = $this->signInAdmin();
        $ledger = app(Ledger::class);
        // OPERATION INCOME is a pool: a branch INTEREST A/C plus HQ's own account.
        $ledger->openingBalance($admin->company_id, Account::Interest, 60000, branch: $admin->branch_id);
        $ledger->openingBalance($admin->company_id, Account::HqInterest, 40000);
        $bank = BankAccount::create(['company_id' => $admin->company_id, 'name' => 'NMB']);

        $this->postJson('/api/v1/hq/transactions', [
            'from_account' => HqFund::OperationIncome->value, 'to_account' => 'bank:'.$bank->id, 'amount' => 50000,
        ])->assertCreated();

        $transaction = HqTransaction::firstOrFail();
        $this->postJson("/api/v1/hq/transactions/{$transaction->id}/approve")->assertOk();

        $this->assertSame(50000.0, $ledger->balance($admin->company_id, Account::Bank, bankAccount: $bank));
        $this->assertSame(30000.0, $ledger->balance($admin->company_id, Account::Interest, branch: $admin->branch_id));
        $this->assertSame(20000.0, $ledger->balance($admin->company_id, Account::HqInterest));
        $this->getJson('/api/v1/hq/transactions?status=approved')->assertJsonPath('data.0.to_account_label', 'NMB');
    }

    public function test_money_sent_to_the_dividend_account_settles_dividends_already_declared(): void
    {
        $admin = $this->signInAdmin();
        $ledger = app(Ledger::class);
        $ledger->openingBalance($admin->company_id, Account::HqInterest, 100000);
        // A declaration credits DIVIDEND ACCOUNT, exactly as DividendService posts it.
        $ledger->journal($admin->company_id, 'DIVIDEND DECLARATION', [
            ['account' => Account::RetainedProfit, 'debit' => 30000.0],
            ['account' => Account::DividendPayable, 'credit' => 30000.0],
        ]);

        $this->postJson('/api/v1/hq/transactions', [
            'from_account' => HqFund::OperationIncome->value, 'to_account' => Account::DividendPayable->value, 'amount' => 30000,
        ])->assertCreated();

        $transaction = HqTransaction::firstOrFail();
        $this->postJson("/api/v1/hq/transactions/{$transaction->id}/approve")->assertOk();

        $this->getJson('/api/v1/hq/transactions?status=approved')->assertJsonPath('data.0.to_account_label', 'Dividend A/C');
        $this->assertSame(70000.0, $ledger->balance($admin->company_id, Account::HqInterest));
        $this->assertSame(0.0, $ledger->balance($admin->company_id, Account::DividendPayable), 'the dividend owed is settled');
    }

    public function test_validation_insufficient_balance_and_delete(): void
    {
        $admin = $this->signInAdmin();

        // The To box takes a shareholders' account, never an HQ one, and RESERVE is not a source (rule 3).
        $this->postJson('/api/v1/hq/transactions', [
            'from_account' => HqFund::OperationIncome->value, 'to_account' => Account::HqDisbursement->value, 'amount' => 100,
        ])->assertUnprocessable()->assertJsonValidationErrors('to_account');
        // RESERVE and DIVIDEND may be sent to their own shareholders' account and to nothing else.
        $this->postJson('/api/v1/hq/transactions', [
            'from_account' => HqFund::Reserve->value, 'to_account' => Account::Company->value, 'amount' => 100,
        ])->assertUnprocessable()->assertJsonValidationErrors('to_account');
        $this->postJson('/api/v1/hq/transactions', [
            'from_account' => HqFund::Dividend->value, 'to_account' => Account::Company->value, 'amount' => 100,
        ])->assertUnprocessable()->assertJsonValidationErrors('to_account');

        $transaction = HqTransaction::create([
            'company_id' => $admin->company_id, 'from_account' => HqFund::OperationPrincipal->value, 'to_account' => Account::Company->value, 'amount' => 5000, 'status' => 'pending',
        ]);

        $this->postJson("/api/v1/hq/transactions/{$transaction->id}/approve")->assertUnprocessable();
        $this->assertSame('pending', $transaction->fresh()->status);

        $this->deleteJson("/api/v1/hq/transactions/{$transaction->id}")->assertOk();
        $this->assertModelMissing($transaction);
    }

    public function test_a_row_raised_before_the_screen_changed_still_labels_and_still_protects_the_reserve(): void
    {
        $admin = $this->signInAdmin();
        // Rows raised when both boxes listed HQ accounts name an Account on each side.
        $legacy = HqTransaction::create([
            'company_id' => $admin->company_id, 'from_account' => Account::HqReserve->value, 'to_account' => Account::HqSaving->value, 'amount' => 5000, 'status' => 'pending',
        ]);

        $this->getJson('/api/v1/hq/transactions')->assertOk()
            ->assertJsonPath('data.0.from_account_label', 'RESERVE ACCOUNT')
            ->assertJsonPath('data.0.to_account_label', 'SAVING ACCOUNT');

        // Rule 3: nothing leaves the HQ RESERVE account on an ordinary HQ transaction.
        $this->postJson("/api/v1/hq/transactions/{$legacy->id}/approve")->assertUnprocessable();
        $this->assertSame('pending', $legacy->fresh()->status);
    }

    public function test_the_reserve_row_goes_to_the_investment_and_only_the_owners_may_approve_it(): void
    {
        $admin = $this->signInAdmin();
        $ledger = app(Ledger::class);
        // All reserve belongs to HQ: a branch RESERVE A/C plus HQ's own, drawn in proportion.
        $ledger->openingBalance($admin->company_id, Account::Reserve, 60000, branch: $admin->branch_id);
        $ledger->openingBalance($admin->company_id, Account::HqReserve, 40000);
        $requester = $this->secondApprover($admin, 'admin');
        $this->actingAs($requester);

        $this->postJson('/api/v1/hq/transactions', [
            'from_account' => HqFund::Reserve->value, 'to_account' => Account::InvestmentReserve->value, 'amount' => 50000,
        ])->assertCreated();
        $transaction = HqTransaction::firstOrFail();

        // Rule 3 keeps the owners' hand on the reserve: Finance may raise it, but not decide it.
        $this->asApprover($requester, fn () => $this->postJson("/api/v1/hq/transactions/{$transaction->id}/approve")->assertForbidden(), $this->secondApprover($admin, 'finance'));
        $this->assertSame('pending', $transaction->fresh()->status);

        $this->asApprover($requester, fn () => $this->postJson("/api/v1/hq/transactions/{$transaction->id}/approve")->assertOk());

        $this->assertSame(50000.0, $ledger->balance($admin->company_id, Account::InvestmentReserve));
        $this->assertSame(30000.0, $ledger->balance($admin->company_id, Account::Reserve, branch: $admin->branch_id));
        $this->assertSame(20000.0, $ledger->balance($admin->company_id, Account::HqReserve));
        $this->getJson('/api/v1/hq/transactions?status=approved')->assertJsonPath('data.0.from_account_label', 'RESERVE')
            ->assertJsonPath('data.0.to_account_label', 'Reserve A/C');
    }

    public function test_the_dividend_row_shows_what_is_owed_pays_it_from_the_income_pool_and_never_more(): void
    {
        $admin = $this->signInAdmin();
        $ledger = app(Ledger::class);
        $ledger->openingBalance($admin->company_id, Account::HqInterest, 100000);
        $ledger->journal($admin->company_id, 'DIVIDEND DECLARATION', [
            ['account' => Account::RetainedProfit, 'debit' => 30000.0],
            ['account' => Account::DividendPayable, 'credit' => 30000.0],
        ]);

        // The row is what the owners are owed, not the income pool it is held in.
        $this->getJson('/api/v1/hq/options/accounts?direction=from')->assertOk()
            ->assertJsonPath('data.4.label', 'DIVIDEND - 30,000');

        // More than was declared cannot be paid, even though the income pool holds it.
        $tooMuch = HqTransaction::create([
            'company_id' => $admin->company_id, 'from_account' => HqFund::Dividend->value,
            'to_account' => Account::DividendPayable->value, 'amount' => 40000, 'status' => 'pending',
        ]);
        $this->postJson("/api/v1/hq/transactions/{$tooMuch->id}/approve")->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->deleteJson("/api/v1/hq/transactions/{$tooMuch->id}")->assertOk();

        $this->postJson('/api/v1/hq/transactions', [
            'from_account' => HqFund::Dividend->value, 'to_account' => Account::DividendPayable->value, 'amount' => 30000, 'charge' => 1000,
        ])->assertCreated();
        $transaction = HqTransaction::firstOrFail();
        $this->postJson("/api/v1/hq/transactions/{$transaction->id}/approve")->assertOk();

        // The money leaves the income pool (with the charge) and the dividend owed is settled.
        $this->assertSame(69000.0, $ledger->balance($admin->company_id, Account::HqInterest));
        $this->assertSame(1000.0, $ledger->balance($admin->company_id, Account::BankCharges));
        $this->assertSame(0.0, $ledger->balance($admin->company_id, Account::DividendPayable));
        $this->getJson('/api/v1/hq/transactions?status=approved')->assertJsonPath('data.0.from_account_label', 'DIVIDEND');
    }

    public function test_permission_and_company_isolation(): void
    {
        $admin = $this->signInAdmin();
        $foreign = HqTransaction::create([
            'company_id' => Company::factory()->create()->id, 'from_account' => Account::HqReserve->value,
            'to_account' => Account::HqSaving->value, 'amount' => 5000, 'status' => 'pending',
        ]);

        $this->postJson("/api/v1/hq/transactions/{$foreign->id}/approve")->assertNotFound();

        $manager = Employee::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'role_id' => $admin->company->roles()->where('key', 'branch_manager')->value('id')]);
        $this->actingAs($manager);
        $this->getJson('/api/v1/hq/balances')->assertForbidden();
        $this->postJson('/api/v1/hq/transactions', [])->assertForbidden();
    }
}
