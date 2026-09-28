<?php

namespace Tests\Feature\Api\Reports\Financial;

use App\Enums\Account;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Employee;
use App\Services\Ledger;
use App\Services\Reports\Financial\CashAccounts;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reports → Financial → Cash & Fund Position (C5): every figure equals its ledger balance, sections add up to all money
 * accounts, reference balances are shown apart, the as-of date and company/branch scope are respected.
 */
class FundPositionReportTest extends TestCase
{
    use RefreshDatabase;

    private Employee $admin;

    private Branch $second;

    private BankAccount $nmb;

    private BankAccount $crdb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
        $this->admin->branch->update(['name' => 'FIRST']);
        $this->second = Branch::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'SECOND']);
        $this->nmb = BankAccount::create(['company_id' => $this->admin->company_id, 'name' => 'NMB']);
        $this->crdb = BankAccount::create(['company_id' => $this->admin->company_id, 'name' => 'CRDB']);
        $this->seedLedger();
    }

    public function test_sections_equal_the_ledger_and_add_up_to_all_money_accounts(): void
    {
        $data = $this->getJson('/api/v1/reports/financial/fund-position?as_of=2026-08-31')->assertOk()->json('data');
        $first = $this->admin->branch_id;
        $second = $this->second->id;

        $physical = collect($data['physical']['lines']);
        $this->assertEquals($this->balance(Account::Company), $physical->firstWhere('key', 'company_cash')['amount']);
        $this->assertEquals(692000, $physical->firstWhere('key', 'company_cash')['amount']);
        $this->assertEquals(300000, $physical->firstWhere('bank_account_id', $this->nmb->id)['amount']);
        $this->assertSame('BANK — NMB', $physical->firstWhere('bank_account_id', $this->nmb->id)['label']);
        $this->assertEquals(250000, $physical->firstWhere('bank_account_id', $this->crdb->id)['amount']);
        $this->assertEquals(40000, $physical->firstWhere('key', 'bank_clearing')['amount'], 'provider receipts without a bank account');
        $this->assertEquals(15000, $physical->where('key', 'teller_cash')->firstWhere('branch_id', $first)['amount']);
        $this->assertEquals(8000, $physical->where('key', 'agent')->firstWhere('branch_id', $second)['amount']);
        $this->assertEquals(6000, $physical->firstWhere('key', 'staff_fund_cash')['amount']);
        $this->assertEquals(1311000, $data['physical']['total']);

        $branches = collect($data['funds']['branches'])->keyBy('branch_id');
        $this->assertEquals(['principal' => 400000, 'interest' => 32000, 'loan_fee' => 5000, 'penalty' => 2000, 'reserve' => 8000, 'insurance' => 1000], $branches[$first]['funds']);
        $this->assertEquals(448000, $branches[$first]['total']);
        $this->assertSame('SECOND', $branches[$second]['branch']);
        $this->assertEquals(200000, $branches[$second]['funds']['principal']);
        foreach ([[Account::Principal, $first], [Account::Interest, $first], [Account::Reserve, $first], [Account::Principal, $second]] as [$account, $branch]) {
            $this->assertEquals($this->balance($account, $branch), $branches[$branch]['funds'][$account->value], $account->value);
        }
        $this->assertEquals(600000, collect($data['funds']['fund_totals'])->firstWhere('account', 'principal')['amount']);
        $this->assertEquals(648000, $data['funds']['branch_funds_total']);
        $this->assertEquals(50000, collect($data['funds']['hq_accounts'])->firstWhere('account', Account::HqInterest->value)['amount']);
        $this->assertEquals(50000, $data['funds']['hq_total']);
        $this->assertEquals(698000, $data['funds']['total']);

        $reference = collect($data['reference']['lines'])->keyBy('account');
        $this->assertEquals(8000, $reference['interest_reserve']['amount']);
        $this->assertEquals(1000, $reference['insurance_reserve']['amount']);
        $this->assertEquals(2000000, $reference['capital']['amount']);
        $this->assertEquals(0, $reference['dividend_payable']['amount']);

        $ledgerMoney = round(array_sum(array_map(fn (Account $account): float => app(Ledger::class)->balance($this->admin->company_id, $account, allBranches: true), CashAccounts::moneyAccounts())), 2);
        $this->assertEquals(2009000, $data['total_money']);
        $this->assertEquals($ledgerMoney, $data['total_money'], 'physical + funds = every money account, nothing counted twice');
        $this->assertTrue($data['balanced']);
    }

    public function test_as_of_date_and_scope(): void
    {
        $july = $this->getJson('/api/v1/reports/financial/fund-position?as_of=2026-07-31')->assertOk()->json();
        $this->assertSame('2026-07-31', $july['filter']['as_of']);
        $this->assertEquals(0, collect($july['data']['physical']['lines'])->firstWhere('key', 'staff_fund_cash')['amount'], 'August entries are excluded');
        $this->assertEquals(2009000 - 6000, $july['data']['total_money']);

        $branch = $this->getJson("/api/v1/reports/financial/fund-position?as_of=2026-08-31&branch_id={$this->second->id}")->assertOk()->json('data');
        $this->assertEquals(0, collect($branch['physical']['lines'])->firstWhere('key', 'company_cash')['amount'], 'HQ accounts are outside a branch scope');
        $this->assertSame([$this->second->id], array_column($branch['funds']['branches'], 'branch_id'));
        $this->assertEquals(208000, $branch['total_money']);

        $this->signInAdmin();
        $other = $this->getJson('/api/v1/reports/financial/fund-position?as_of=2026-08-31')->assertOk()->json('data');
        $this->assertEquals(0, $other['total_money'], 'another company sees none of these balances');

        $teller = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'role_id' => $this->admin->company->roles()->where('key', 'teller')->value('id')]);
        $this->actingAs($teller)->getJson('/api/v1/reports/financial/fund-position')->assertForbidden();
    }

    private function balance(Account $account, ?int $branch = null): float
    {
        return app(Ledger::class)->balance($this->admin->company_id, $account, $branch);
    }

    /**
     * Capital 2,000,000 into the company account; money moved to banks, branch principal, teller/agent cash; collections into
     * branch funds and bank clearing; an HQ interest pool; staff fund cash in August.
     */
    private function seedLedger(): void
    {
        $ledger = app(Ledger::class);
        $company = $this->admin->company_id;
        $first = $this->admin->branch_id;
        $second = $this->second->id;
        $july = CarbonImmutable::parse('2026-07-10');

        $ledger->journal($company, 'CAPITAL', [
            ['account' => Account::Company, 'debit' => 2000000],
            ['account' => Account::Capital, 'credit' => 2000000],
        ], null, $july);
        $ledger->journal($company, 'MOVES', [
            ['account' => Account::Bank, 'bank' => $this->nmb->id, 'debit' => 300000],
            ['account' => Account::Bank, 'bank' => $this->crdb->id, 'debit' => 250000],
            ['account' => Account::Principal, 'branch' => $first, 'debit' => 500000],
            ['account' => Account::Principal, 'branch' => $second, 'debit' => 208000],
            ['account' => Account::HqInterest, 'debit' => 50000],
            ['account' => Account::Company, 'credit' => 1308000],
        ], null, $july);
        $ledger->journal($company, 'BRANCH CASH', [
            ['account' => Account::TellerCash, 'branch' => $first, 'employee' => $this->admin->id, 'debit' => 15000],
            ['account' => Account::Agent, 'branch' => $second, 'debit' => 8000],
            ['account' => Account::Principal, 'branch' => $first, 'credit' => 15000],
            ['account' => Account::Principal, 'branch' => $second, 'credit' => 8000],
        ], null, $july);
        $ledger->journal($company, 'LOAN OUT', [
            ['account' => Account::LoanReceivable, 'branch' => $first, 'debit' => 85000],
            ['account' => Account::Principal, 'branch' => $first, 'credit' => 85000],
        ], null, $july);
        $ledger->journal($company, 'COLLECTIONS', [
            ['account' => Account::Interest, 'branch' => $first, 'debit' => 32000],
            ['account' => Account::Reserve, 'branch' => $first, 'debit' => 8000],
            ['account' => Account::LoanFee, 'branch' => $first, 'debit' => 5000],
            ['account' => Account::Penalty, 'branch' => $first, 'debit' => 2000],
            ['account' => Account::Insurance, 'branch' => $first, 'debit' => 1000],
            ['account' => Account::InterestIncome, 'branch' => $first, 'credit' => 32000],
            ['account' => Account::InterestReserve, 'branch' => $first, 'credit' => 8000],
            ['account' => Account::FeeIncome, 'branch' => $first, 'credit' => 5000],
            ['account' => Account::PenaltyIncome, 'branch' => $first, 'credit' => 2000],
            ['account' => Account::InsuranceReserve, 'branch' => $first, 'credit' => 1000],
        ], null, $july);
        $ledger->journal($company, 'PROVIDER RECEIPT', [
            ['account' => Account::Bank, 'debit' => 40000],
            ['account' => Account::Suspense, 'credit' => 40000],
        ], null, $july);
        $ledger->journal($company, 'STAFF FUND', [
            ['account' => Account::StaffFundCash, 'debit' => 6000],
            ['account' => Account::StaffFund, 'credit' => 6000],
        ], null, CarbonImmutable::parse('2026-08-05'));
    }
}
