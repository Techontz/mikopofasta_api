<?php

namespace Tests\Feature\Api\Reports;

use App\Enums\Account;
use App\Enums\LoanStatus;
use App\Models\AccountingPeriod;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\ExpenseRequest;
use App\Models\ExpenseType;
use App\Models\Group;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanTransaction;
use App\Models\Penalty;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvanceCategory;
use App\Models\ShareHolder;
use App\Services\AccessControl;
use App\Services\CapitalContributions;
use App\Services\DashboardStatistics;
use App\Services\ExpenseApproval;
use App\Services\Ledger;
use App\Services\LoanService;
use App\Services\PaymentService;
use App\Services\PeriodClose;
use App\Services\Reports\Financial\CashAccounts;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reports and dashboards take one server-authoritative figure per concept: ledger income/expenses without closing
 * entries, capital and transfers never counted as income, KPIs computed on the server, one outstanding per loan,
 * written-off loans outside the portfolio and reversed records ignored.
 */
class MoneyFlowReportsTest extends TestCase
{
    use BuildsReportFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
    }

    public function test_capital_position_shows_real_income_and_expenses_for_a_closed_month(): void
    {
        $ledger = app(Ledger::class);
        $company = $this->admin->company_id;
        $branch = $this->admin->branch_id;
        $june = fn (int $day): CarbonImmutable => CarbonImmutable::create(2026, 6, $day);

        $ledger->openingBalance($company, Account::Company, 1000000, 'CAPITAL', date: $june(1));
        $ledger->transfer($company, ['account' => Account::Company], ['account' => Account::Principal, 'branch' => $branch], 400000, 'FLOAT', date: $june(2));
        $ledger->journal($company, 'LOAN RETURN', [
            ['account' => Account::Interest, 'branch' => $branch, 'debit' => 80000],
            ['account' => Account::Reserve, 'branch' => $branch, 'debit' => 20000],
            ['account' => Account::InterestIncome, 'branch' => $branch, 'credit' => 100000],
            ['account' => Account::Penalty, 'branch' => $branch, 'debit' => 5000],
            ['account' => Account::PenaltyIncome, 'branch' => $branch, 'credit' => 5000],
        ], null, $june(10), $branch);
        $ledger->transfer($company, ['account' => Account::Interest, 'branch' => $branch], ['account' => Account::OperatingExpense, 'branch' => $branch], 30000, 'Expenses: RENT', date: $june(15));

        $periodClose = app(PeriodClose::class);
        $periodClose->close($periodClose->calculate($company, $june(1)), $this->admin);
        $this->assertTrue(JournalEntry::where('source_type', (new AccountingPeriod)->getMorphClass())->exists(), 'closing entries were posted');

        $position = $this->getJson('/api/v1/capital/position?from=2026-06-01&to=2026-06-30')->assertOk()->json('data');

        $this->assertEquals(105000, $position['income']);
        $this->assertEquals(30000, $position['expenses']);
        $this->assertEquals(75000, $position['net_income']);
        $this->assertEquals(20000, $position['reserve_from_interest']);
        $this->assertEquals(100000, collect($position['income_breakdown'])->firstWhere('key', Account::InterestIncome->value)['amount']);
        $this->assertEquals(30000, collect($position['expense_breakdown'])->firstWhere('key', Account::OperatingExpense->value)['amount']);
        $this->assertSame('2026-06-01', $position['from']);

        $allTime = $this->getJson('/api/v1/capital/position')->assertOk()->json('data');
        $this->assertEquals(75000, $allTime['net_income'], 'closing entries are excluded for all time too');

        $pnl = $this->getJson('/api/v1/reports/financial/branch-pnl?branch_id=all&from=2026-06-01&to=2026-06-30')->assertOk()->json('data.totals');
        $this->assertEquals(80000 + 5000, $pnl['total_income'], 'branch P&L results are unchanged by the shared closing-entry helper');
        $this->assertEquals(30000, $pnl['expenses']);

        $groups = collect($position['balances']['money_groups'])->keyBy('key');
        $this->assertEquals(600000, $groups['company_cash']['amount']);
        $this->assertEquals(400000, $groups['lending_cash']['amount']);
        $this->assertEquals(80000 - 30000 + 20000 + 5000, $groups['branch_income_funds']['amount']);
        $this->assertEquals(round(600000 + 400000 + 75000, 2), $position['balances']['total_money_assets']);
        $this->assertEquals(1000000, $position['balances']['total_cash_and_bank']);
    }

    public function test_every_money_asset_account_belongs_to_exactly_one_cash_group(): void
    {
        $grouped = collect(CashAccounts::GROUPS)->flatMap(fn (array $group): array => $group[1]);

        $this->assertSame($grouped->count(), $grouped->unique()->count());
        $this->assertEqualsCanonicalizing(
            array_map(fn (Account $account): string => $account->value, CashAccounts::moneyAccounts()),
            $grouped->map(fn (Account $account): string => $account->value)->all(),
        );
        $this->assertNotContains(Account::Bank, CashAccounts::moneyAccounts(includeBank: false));
    }

    public function test_capital_and_float_received_today_are_not_income_and_accepted_expenses_are_expenses(): void
    {
        $companyId = $this->admin->company_id;
        $branchId = $this->admin->branch_id;
        $holder = ShareHolder::create(['company_id' => $companyId, 'first_name' => 'ASHA', 'last_name' => 'HOLDER', 'mobile' => '0777', 'email' => 'asha@example.com', 'date_of_birth' => '1990-01-01']);

        $before = $this->getJson('/api/v1/dashboard')->assertOk()->json('data.today');

        app(CapitalContributions::class)->contribute($holder, 2000000, 'CASH', null, $this->admin);
        app(Ledger::class)->transfer($companyId, ['account' => Account::Company], ['account' => Account::Principal, 'branch' => $branchId], 500000, 'FLOAT');

        $afterCapital = $this->getJson('/api/v1/dashboard')->assertOk()->json('data');
        $today = $afterCapital['today'];
        $this->assertEquals($before['total_income'], $today['total_income']);
        $this->assertEquals($before['total_expenses'], $today['total_expenses']);
        $this->assertEquals(2000000, $today['capital_received']);
        $this->assertArrayNotHasKey('capital_income', $today);
        $this->assertArrayNotHasKey('transfer_income', $today);

        $loan = $this->cashedOutLoan(daysAgo: 0);
        $this->repay($loan, 130000);
        $type = ExpenseType::create(['company_id' => $companyId, 'scope' => 'branch', 'name' => 'RENT']);
        app(Ledger::class)->transfer($companyId, ['account' => Account::Company], ['account' => Account::PettyCash, 'branch' => $branchId], 7000, 'PETTY CASH');
        $expense = ExpenseRequest::create([
            'company_id' => $companyId, 'scope' => 'branch', 'branch_id' => $branchId, 'expense_type_id' => $type->id,
            'amount' => 7000, 'description' => 'rent', 'status' => 'pending', 'request_date' => today()->subDays(3),
        ]);
        app(ExpenseApproval::class)->accept($expense, $this->admin, 7000, null);

        $today = $this->getJson('/api/v1/dashboard')->assertOk()->json('data.today');
        $this->assertEquals(24000, $today['interest_income'], 'interest 30,000 less the 20 % reserve');
        $this->assertEquals(6000, $today['reserve_amount']);
        $this->assertEquals(24000 + $today['loan_fee_income'] + $today['penalty_income'] + $today['recovery_income'], $today['total_income']);
        $this->assertEquals(7000, $today['expenses'], 'expense approved today although requested earlier');
        $this->assertEquals(7000, $today['total_expenses']);
        $this->assertEquals(round($today['total_income'] - 7000, 2), $today['net_income']);
        $this->assertEquals(100000, $today['weekly_withdrawal']);
        $this->assertEquals(130000, $today['weekly_deposit']);

        $ledger = app(Ledger::class);
        $balances = $this->getJson('/api/v1/dashboard')->assertOk()->json('data.account_balances');
        $this->assertSame('Company A/C', array_key_first($balances));
        $this->assertEquals(6000, $ledger->balance($companyId, Account::Reserve, allBranches: true));
        $this->assertEquals(0, $balances['Reserve A/C'], 'branch reserve is HQ reserve until HQ sends it to the Investment RESERVE A/C');
        $this->assertEquals(round(array_sum($afterCapital['account_balances']), 2), $afterCapital['account_balances_total']);
        $this->assertEquals($afterCapital['account_balances_total'], $afterCapital['cards']['account_balance']);
    }

    public function test_branch_scoped_employees_never_receive_company_money_on_the_dashboard(): void
    {
        $companyId = $this->admin->company_id;
        $branchId = $this->admin->branch_id;
        $holder = ShareHolder::create(['company_id' => $companyId, 'first_name' => 'ASHA', 'last_name' => 'HOLDER', 'mobile' => '0777', 'email' => 'asha@example.com', 'date_of_birth' => '1990-01-01']);
        app(CapitalContributions::class)->contribute($holder, 2000000, 'CASH', null, $this->admin);
        app(Ledger::class)->transfer($companyId, ['account' => Account::Company], ['account' => Account::Principal, 'branch' => $branchId], 500000, 'FLOAT');

        $admin = $this->getJson('/api/v1/dashboard')->assertOk()->json('data');
        $this->assertNotNull($admin['cards']['account_balance']);
        $this->assertEquals(2000000, $admin['today']['capital_received']);

        $manager = Employee::factory()->create([
            'company_id' => $companyId, 'branch_id' => $branchId,
            'role_id' => $this->admin->company->roles()->where('key', 'branch_manager')->value('id'),
        ]);
        foreach (['capital.view', 'accounting.view', 'hq.manage'] as $permission) {
            $manager->permissionOverrides()->create(['permission' => $permission, 'granted' => true]);
        }

        $data = $this->actingAs($manager)->getJson('/api/v1/dashboard')->assertOk()->json('data');

        $this->assertEquals(0, $data['cards']['account_balance'], 'the branch green card is its PETTY CASH A/C, never company money');
        $this->assertSame('Petty Cash', $data['cards']['account_balance_title']);
        $this->assertNull($data['account_balances']);
        $this->assertNull($data['account_balances_total']);
        $this->assertNull($data['header_accounts']);
        $this->assertNull($data['branch_accounts']);
        $this->assertNull($data['operating_income'], 'a branch never sees HQ operating income');
        foreach (DashboardStatistics::COMPANY_MONEY_MOVEMENTS as $key) {
            $this->assertNull($data['today'][$key], $key);
        }
        $this->assertNull($data['finance_kpis']['account_balance']);
        $this->assertNull($data['finance_kpis']['hq_accounts']);
        $this->assertNull($data['finance_kpis']['company_accounts']);
        $this->assertStringNotContainsString('2000000', json_encode($data));
    }

    public function test_investment_lists_cash_banks_reserve_and_contributed_assets(): void
    {
        $companyId = $this->admin->company_id;
        app(Ledger::class)->transfer($companyId, ['account' => Account::Capital], ['account' => Account::MotorVehicles], 48000000, 'ASSET CAPITAL');

        $data = $this->getJson('/api/v1/dashboard')->assertOk()->json('data');

        app(Ledger::class)->transfer($companyId, ['account' => Account::Capital], ['account' => Account::Equipment], 1500000, 'ASSET CAPITAL');
        $data = $this->getJson('/api/v1/dashboard')->assertOk()->json('data');

        $this->assertSame(['Company A/C', 'Reserve A/C', 'Assets'], array_keys($data['account_balances']), 'assets shown as one total, not listed');
        $this->assertEquals(49500000, $data['account_balances']['Assets']);
        $this->assertEquals($data['account_balances_total'], $data['cards']['account_balance']);
        $this->assertSame('Company A/C + banks + reserve + assets', $data['cards']['account_balance_label']);
    }

    /**
     * HQ and Finance run the money, but the Investment is the owners' position: they see the funds the company floated to HQ
     * and the income HQ holds instead, never the Company A/C, the banks or the assets.
     */
    public function test_hq_and_finance_see_hq_funds_instead_of_the_investment(): void
    {
        $companyId = $this->admin->company_id;
        $ledger = app(Ledger::class);
        $bank = BankAccount::create(['company_id' => $companyId, 'name' => 'NMB']);
        $ledger->openingBalance($companyId, Account::Company, 9000000, 'CAPITAL');
        $ledger->openingBalance($companyId, Account::Bank, 4000000, bankAccount: $bank);
        $ledger->openingBalance($companyId, Account::MotorVehicles, 7000000, 'ASSET CAPITAL');
        $ledger->openingBalance($companyId, Account::Principal, 2000000, 'FLOAT TO HQ');
        $ledger->openingBalance($companyId, Account::Interest, 150000, branch: $this->admin->branch_id);
        $ledger->openingBalance($companyId, Account::HqInterest, 50000, 'STAFF ADVANCE INTEREST');

        $owner = $this->getJson('/api/v1/dashboard')->assertOk()->json('data');
        $this->assertSame('Account Balance', $owner['cards']['account_balance_title']);
        $this->assertEquals(20000000, $owner['cards']['account_balance'], 'company 9m + bank 4m + assets 7m');

        $finance = $this->employeeWithRole('finance');
        $this->assertFalse($finance->can('capital.view'));
        $data = $this->actingAs($finance)->getJson('/api/v1/dashboard')->assertOk()->json('data');

        $this->assertSame('HQ Funds', $data['cards']['account_balance_title']);
        $this->assertNull($data['today']['capital_received'], 'capital received is the owners\' money, never shown to HQ or Finance');
        $this->assertNotNull($data['today']['float_to_hq']);
        $this->assertEquals(2000000 + 150000 + 50000, $data['cards']['account_balance'], 'the float received plus the interest HQ holds');
        $this->assertSame(
            ['OPERATION PRINCIPAL', 'OPERATION INCOME', 'FUND', 'RESERVE', 'UNMATCHED', 'SAVINGS', 'PROFIT', 'DIVIDENDS'],
            array_keys($data['account_balances']),
            'exactly the HQ accounts, the empty ones included',
        );
        $this->assertEquals(150000 + 50000, $data['account_balances']['OPERATION INCOME'], 'the branch pool and the HQ account are one row, never two');
        $this->assertNull($data['account_memos'], 'the memo lines belong to the owners\' Company Account List only');
        $this->assertEquals($data['cards']['account_balance'], $data['account_balances_total']);
        $this->assertArrayNotHasKey('Company A/C', $data['account_balances']);
        $this->assertArrayNotHasKey('Assets', $data['account_balances']);
        $this->assertNull($data['finance_kpis']['company_accounts']);
        $this->assertStringNotContainsString('9000000', json_encode($data['cards']).json_encode($data['account_balances']));

        // §5 / §45: one Operating Income total; the categories are its sources and add up to it — never extra cash.
        $this->assertEquals(150000 + 50000, $data['operating_income']['total']);
        $this->assertSame(['interest', 'loan_fee', 'penalty'], array_column($data['operating_income']['sources'], 'key'));
        $this->assertEquals($data['operating_income']['total'], array_sum(array_column($data['operating_income']['sources'], 'amount')));
    }

    public function test_unmatched_savings_profit_and_dividends_are_listed_but_never_added_to_the_hq_funds_total(): void
    {
        $companyId = $this->admin->company_id;
        $ledger = app(Ledger::class);
        $ledger->openingBalance($companyId, Account::Principal, 1000000, 'FLOAT TO HQ');
        $ledger->openingBalance($companyId, Account::StaffFundCash, 200000, 'STAFF FUND');
        $ledger->openingBalance($companyId, Account::HqSaving, 25000, 'CUSTOMER SAVINGS', branch: $this->admin->branch_id);
        app(PaymentService::class)->recordUnmatched($companyId, ['amount' => 40000, 'channel' => 'BANK', 'provider' => 'CRDB Bank', 'paid_on' => today()->toDateString()], $this->admin);
        // A closed month: 300,000 of income now belongs to profit, 90,000 of which was declared as dividends — the cash
        // itself is still in the interest pool.
        $ledger->transfer($companyId, ['account' => Account::RetainedProfit], ['account' => Account::Interest, 'branch' => $this->admin->branch_id], 300000, 'CLOSED MONTH');
        $ledger->transfer($companyId, ['account' => Account::DividendPayable], ['account' => Account::RetainedProfit], 90000, 'DIVIDEND DECLARED');

        $data = $this->actingAs($this->employeeWithRole('finance'))->getJson('/api/v1/dashboard')->assertOk()->json('data');

        $this->assertEquals(1000000, $data['account_balances']['OPERATION PRINCIPAL']);
        $this->assertEquals(300000, $data['account_balances']['OPERATION INCOME']);
        $this->assertEquals(200000, $data['account_balances']['FUND']);
        $this->assertEquals(210000, $data['account_balances']['PROFIT']);
        $this->assertEquals(90000, $data['account_balances']['DIVIDENDS']);
        $this->assertArrayNotHasKey('AGENT A/C', $data['account_balances']);
        $this->assertEquals(40000, $data['account_balances']['UNMATCHED'], 'the unallocated money of Payments → Suspense Account');
        $this->assertEquals(25000, $data['account_balances']['SAVINGS'], 'customer savings held');
        $this->assertEquals(1500000, $data['account_balances_total'], 'principal + income + fund; unmatched, savings, profit and dividends are listed, never added');
        $this->assertEquals(1500000, $data['cards']['account_balance']);
    }

    public function test_branch_list_is_monthly_except_the_petty_cash_balance(): void
    {
        $companyId = $this->admin->company_id;
        $branchId = $this->admin->branch_id;
        $ledger = app(Ledger::class);
        $headOffice = Branch::factory()->create(['company_id' => $companyId, 'is_head_office' => true]);
        $ledger->openingBalance($companyId, Account::PettyCash, 50000, branch: $branchId);
        $ledger->transfer($companyId, ['account' => Account::PettyCash, 'branch' => $branchId], ['account' => Account::OperatingExpense, 'branch' => $branchId], 12000, 'WATER BILL');

        $customer = Customer::factory()->create(['company_id' => $companyId, 'branch_id' => $branchId]);
        $deposit = fn (float $principal, string $date, bool $reversed = false): LoanTransaction => LoanTransaction::create([
            'company_id' => $companyId, 'branch_id' => $branchId, 'customer_id' => $customer->id, 'type' => 'deposit', 'description' => 'REPAYMENT',
            'amount' => $principal, 'principal' => $principal, 'transaction_date' => $date, 'reversed_at' => $reversed ? now() : null,
        ]);
        $deposit(10000, today()->toDateString());
        $deposit(4000, today()->startOfMonth()->toDateString());
        $deposit(9000, today()->toDateString(), reversed: true);
        $deposit(80000, today()->subMonthNoOverflow()->endOfMonth()->toDateString());

        // Salary advance column = the full amount repaid in the month (capital + profit), a report figure only.
        $ledger->transfer($companyId, ['account' => Account::SalaryAdvanceIncome, 'branch' => $branchId], ['account' => Account::HqInterest], 4000, 'SALARY ADVANCE DEPOSIT');
        $category = SalaryAdvanceCategory::create(['company_id' => $companyId, 'name' => 'SA', 'interest_rate' => 20, 'amount_from' => 1000, 'amount_to' => 500000]);
        $advance = fn (array $attributes = []) => SalaryAdvance::create($attributes + [
            'company_id' => $companyId, 'branch_id' => $branchId, 'customer_id' => $customer->id, 'salary_advance_category_id' => $category->id,
            'amount' => 100000, 'interest_rate' => 20, 'total_payable' => 120000, 'fee' => 0, 'status' => 'active', 'approved_at' => now(),
        ]);
        $advance()->payments()->create(['amount' => 60000, 'paid_on' => today()->toDateString()]);
        $advance()->payments()->create(['amount' => 5000, 'paid_on' => today()->subMonthNoOverflow()->endOfMonth()->toDateString()]);
        $advance(['reversed_at' => now()])->payments()->create(['amount' => 7000, 'paid_on' => today()->toDateString()]);

        $list = $this->getJson('/api/v1/dashboard')->assertOk()->json('data.branch_accounts');

        $this->assertSame(today()->format('F Y'), $list['month']);
        $this->assertNotContains($headOffice->name, array_column($list['rows'], 'name'), 'Head Office is not a branch');
        $row = collect($list['rows'])->firstWhere('name', $this->admin->branch->name);
        $this->assertSame(['name', 'petty_cash', 'principal_repaid', 'interest', 'loan_fee', 'penalty', 'reserve', 'salary_advance', 'cash_pending'], array_keys($row), 'no agent or insurance columns');
        $this->assertEquals(38000, $row['petty_cash'], 'the petty cash balance available now');
        $this->assertEquals(14000, $row['principal_repaid'], 'this month only, reversed repayments excluded');
        $this->assertEquals(0, $row['interest'], 'monthly collections, not balances');
        $this->assertEquals(60000, $row['salary_advance'], 'full salary advance repaid this month, reversed advances excluded');
        $this->assertEquals(0, $row['cash_pending']);
        $this->assertEquals(14000, $list['total']['principal_repaid']);
        $this->assertEquals(60000, $list['total']['salary_advance']);
    }

    public function test_branch_scoped_dashboard_cards_cover_the_employee_branch_only(): void
    {
        $otherBranch = $this->otherBranch();
        $this->cashedOutLoan(daysAgo: 0);
        $this->cashedOutLoan(daysAgo: 0, branch: $otherBranch, principal: 300000);
        $this->cashedOutLoan(daysAgo: 30)->update(['status' => LoanStatus::Default]);
        $this->cashedOutLoan(daysAgo: 30, branch: $otherBranch, principal: 500000)->update(['status' => LoanStatus::Default]);

        $company = $this->getJson('/api/v1/dashboard')->assertOk()->json('data.cards');
        $this->assertEquals(400000, $company['loan_withdrawal']);

        $manager = Employee::factory()->create([
            'company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id,
            'role_id' => $this->admin->company->roles()->where('key', 'branch_manager')->value('id'),
        ]);
        $cards = $this->actingAs($manager)->getJson('/api/v1/dashboard')->assertOk()->json('data.cards');

        $this->assertEquals(100000, $cards['loan_withdrawal']);
        $this->assertEquals(130000, $cards['default_loan']);
        $this->assertEquals(130000 + 650000, $company['default_loan']);
    }

    public function test_branch_dashboard_green_card_is_its_petty_cash_and_every_figure_covers_the_branch_only(): void
    {
        $ledger = app(Ledger::class);
        $companyId = $this->admin->company_id;
        $branchId = $this->admin->branch_id;
        $otherBranch = $this->otherBranch();
        $ledger->openingBalance($companyId, Account::PettyCash, 75000, branch: $branchId);
        $ledger->openingBalance($companyId, Account::PettyCash, 999000, branch: $otherBranch);

        $this->repay($this->cashedOutLoan(daysAgo: 0), 130000);
        $this->repay($this->cashedOutLoan(daysAgo: 0, branch: $otherBranch, principal: 300000), 390000);

        // A branch expense (water bill) is paid out of that branch petty cash once HQ accepted it.
        $type = ExpenseType::create(['company_id' => $companyId, 'scope' => 'branch', 'name' => 'WATER']);
        $expense = fn (int $branch, float $amount): ExpenseRequest => ExpenseRequest::create([
            'company_id' => $companyId, 'scope' => 'branch', 'branch_id' => $branch, 'expense_type_id' => $type->id,
            'amount' => $amount, 'description' => 'water bill', 'status' => 'pending', 'request_date' => today(),
        ]);
        app(ExpenseApproval::class)->accept($expense($branchId, 7500), $this->admin, 7500, null);
        app(ExpenseApproval::class)->accept($expense($otherBranch->id, 20000), $this->admin, 20000, null);

        $company = $this->getJson('/api/v1/dashboard')->assertOk()->json('data');
        $this->assertSame('Account Balance', $company['cards']['account_balance_title']);
        $this->assertEquals(2, $company['today']['all_customers']);
        $this->assertEquals(130000 + 390000, $company['today']['weekly_deposit']);

        $manager = Employee::factory()->create([
            'company_id' => $companyId, 'branch_id' => $branchId,
            'role_id' => $this->admin->company->roles()->where('key', 'branch_manager')->value('id'),
        ]);
        $data = $this->actingAs($manager)->getJson('/api/v1/dashboard')->assertOk()->json('data');
        $today = $data['today'];

        $this->assertEquals(75000 - 7500, $data['cards']['account_balance'], 'the petty cash this branch holds, less the water bill it paid');
        $this->assertSame('Petty Cash', $data['cards']['account_balance_title']);
        $this->assertEquals(1, $today['all_customers']);
        $this->assertEquals(1, $today['weekly_customers']);
        $this->assertEquals(130000, $today['weekly_deposit']);
        $this->assertEquals(100000, $today['weekly_withdrawal']);
        $this->assertEquals(round(30000 * 0.8, 2), $today['interest_income'], 'income from this branch ledger only');
        $this->assertEquals(7500, $today['expenses'], 'the water bill is this branch expense only');
        $this->assertEquals(round(30000 * 0.2, 2), $today['reserve_amount']);
        $this->assertEquals(round($company['today']['interest_income'] - $today['interest_income'], 2), round(90000 * 0.8, 2));
        $this->assertEquals(1, collect($data['customer_types'])->firstWhere('label', 'All Customer')['all']);
    }

    public function test_finance_kpis_are_computed_on_the_server_within_permission_and_company_scope(): void
    {
        $loan = $this->cashedOutLoan(daysAgo: 20);
        $first = $this->penalise($loan, 36500);
        $this->penalise($loan, 36500);
        $first->update(['paid_amount' => 6500]);
        $waived = $this->penalise($loan, 10000);
        $waived->update(['paid_amount' => 3500, 'is_waived' => true]);

        $category = SalaryAdvanceCategory::create(['company_id' => $this->admin->company_id, 'name' => 'SA', 'interest_rate' => 20, 'amount_from' => 1000, 'amount_to' => 50000]);
        $advance = fn (array $attributes) => SalaryAdvance::create($attributes + [
            'company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'customer_id' => $loan->customer_id,
            'salary_advance_category_id' => $category->id, 'amount' => 15000, 'interest_rate' => 20, 'total_payable' => 18000, 'fee' => 0,
        ]);
        $old = $advance(['status' => 'active', 'approved_at' => now()->subMonths(3)]);
        $old->payments()->create(['amount' => 4000, 'paid_on' => today()->subMonths(2)->toDateString()]);
        $advance(['status' => 'active', 'approved_at' => now()]);
        $done = $advance(['status' => 'done', 'approved_at' => now()->subMonths(4), 'total_payable' => 36000]);
        $done->payments()->create(['amount' => 36000, 'paid_on' => today()->subMonths(3)->toDateString()]);
        $advance(['status' => 'active', 'approved_at' => now()->subMonths(3), 'reversed_at' => now()]);

        $other = Company::factory()->create();
        $otherBranch = Branch::factory()->create(['company_id' => $other->id]);
        $otherCustomer = Customer::factory()->create(['company_id' => $other->id, 'branch_id' => $otherBranch->id]);
        $otherLoan = Loan::factory()->create(['customer_id' => $otherCustomer->id]);
        Penalty::create(['company_id' => $other->id, 'branch_id' => $otherBranch->id, 'customer_id' => $otherCustomer->id, 'loan_id' => $otherLoan->id, 'amount' => 999999, 'penalty_date' => today()->toDateString()]);

        $kpis = $this->getJson('/api/v1/dashboard')->assertOk()->json('data.finance_kpis');

        $this->assertEquals(['collected' => 10000, 'due' => 76500, 'percent' => 13.07, 'remaining' => 66500], $kpis['penalty']);
        $this->assertEquals(['collected' => 40000, 'due' => 72000, 'percent' => 55.56], $kpis['salary_advance']['received']);
        $this->assertEquals(['collected' => 4000, 'due' => 18000, 'unpaid' => 14000, 'percent' => 77.78, 'count' => 1], $kpis['salary_advance']['default']);
        $this->assertEquals(['active' => 2, 'new' => 1, 'old' => 1], $kpis['salary_advance']['customers']);
        $this->assertCount(count(Account::hqAccounts()), $kpis['hq_accounts']['rows']);
        $this->assertEquals(round(array_sum(array_column($kpis['hq_accounts']['rows'], 'balance')), 2), $kpis['hq_accounts']['total']);
        $this->assertEquals(['label' => 'HQ accounts', 'amount' => $kpis['hq_accounts']['total']], $kpis['account_balance']);
        $this->assertEquals(round(array_sum(array_column($kpis['company_accounts']['rows'], 'balance')), 2), $kpis['company_accounts']['total']);

        $this->actingAs($this->employeeWithRole('loan_officer'));
        $restricted = $this->getJson('/api/v1/dashboard')->assertOk()->json('data.finance_kpis');
        $this->assertNull($restricted['penalty']);
        $this->assertNull($restricted['salary_advance']);
        $this->assertNull($restricted['hq_accounts']);
        $this->assertNull($restricted['company_accounts']);
        $this->assertNull($restricted['account_balance'], 'branch-scoped staff never see company money');

        $manager = $this->employeeWithRole('branch_manager', $this->otherBranch()->id);
        $this->assertEquals(['collected' => 0, 'due' => 0, 'percent' => 0, 'remaining' => 0], app(DashboardStatistics::class)->penaltyKpi($manager));
        $this->assertNotNull(app(AccessControl::class)->branchIds($manager));
    }

    public function test_one_remaining_balance_for_a_loan_with_a_penalty_on_every_screen(): void
    {
        $group = Group::create(['company_id' => $this->admin->company_id, 'name' => 'UMOJA']);
        $loan = $this->cashedOutLoan(daysAgo: 10, customer: []);
        $loan->customer->update(['group_id' => $group->id]);
        $this->penalise($loan, 10000);
        $this->repay($loan, 60000);

        $expected = app(LoanService::class)->outstanding($loan->fresh())['total'];
        $this->assertEquals(100000 + 30000 + 10000 - 60000, $expected);

        $groupRow = collect($this->getJson("/api/v1/groups/{$group->id}")->assertOk()->json('data'))->firstWhere('id', $loan->id);
        $this->assertEquals($expected, $groupRow['remain']);
        $this->assertEquals(60000, $groupRow['paid_amount']);
        $this->assertEquals($expected, $loan->fresh()->remaining_amount);
        $this->assertEquals($expected, collect($this->getJson('/api/v1/reports/repayment')->assertOk()->json('data.rows'))->firstWhere('id', $loan->id)['remain']);
        $this->assertEquals($expected, $this->getJson("/api/v1/loans/{$loan->id}")->assertOk()->json('data.outstanding.total'));
    }

    public function test_written_off_loans_leave_the_portfolio_and_pending_figures(): void
    {
        $open = $this->cashedOutLoan(daysAgo: 30);
        $writtenOff = $this->cashedOutLoan(daysAgo: 30);
        $this->repay($writtenOff, 20000);
        app(LoanService::class)->writeOff($writtenOff->fresh(), $this->admin);
        $this->assertSame(LoanStatus::WrittenOff, $writtenOff->fresh()->status);

        $summary = $this->getJson('/api/v1/reports/portfolio')->assertOk()->json('data.summary');
        $this->assertEquals(100000, $summary['outstanding_principal']);
        $this->assertEquals(130000, $summary['outstanding_total']);
        $this->assertEquals($summary['outstanding_total'], $this->getJson('/api/v1/dashboard')->json('data.cards.loan_outstanding'), 'the dashboard Total Loan Outstanding card is the Loan Portfolio figure (written-off loans excluded)');
        $this->assertSame(1, $summary['written_off_count']);
        $this->assertEquals(80000, $summary['written_off_principal']);
        $this->assertEquals(110000, $summary['written_off_outstanding']);

        $row = collect($this->getJson('/api/v1/reports/branchwise')->assertOk()->json('data.rows'))->firstWhere('branch_id', $this->admin->branch_id);
        $this->assertEquals(260000, $row['receivable']);
        $this->assertEquals(20000, $row['received']);
        $this->assertEquals(130000, $row['pending'], 'only the open loan is pending');
        $this->assertEquals(110000, $row['written_off']);
        $this->assertNotNull($open->fresh());
    }

    public function test_reversed_repayments_are_ignored_by_report_balances_and_sums(): void
    {
        $loan = $this->cashedOutLoan(daysAgo: 5);
        $this->repay($loan, 30000, daysAgo: 2);
        $this->repay($loan, 20000, daysAgo: 1);
        $reversed = $loan->transactions()->where('type', 'deposit')->orderBy('id')->firstOrFail();
        $reversed->update(['reversed_at' => now()]);

        $collection = collect($this->getJson('/api/v1/reports/collection')->assertOk()->json('data.rows'))->firstWhere('id', $loan->id);
        $this->assertEquals(20000, $collection['paid']);
        $this->assertEquals(110000, $collection['remain']);
        $received = $this->getJson('/api/v1/reports/received?from='.today()->subDays(3)->toDateString().'&to='.today()->toDateString())->assertOk()->json('data.totals');
        $this->assertEquals(20000, $received['amount']);

        // Every payment carries a status: the reversed one is listed but not counted, money still pending is totalled apart.
        app(PaymentService::class)->recordCash($loan->fresh(), 5000, 'CASH', $this->admin);
        $report = $this->getJson('/api/v1/reports/received?from='.today()->subDays(3)->toDateString().'&to='.today()->toDateString())->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['completed', 'reversed', 'pending_verification'], array_values(array_unique(array_column($report['rows'], 'status'))));
        $this->assertEquals([20000, 5000], [$report['totals']['amount'], $report['totals']['pending']]);

        // A chosen branch shows its whole history, not only today.
        $today = collect($this->getJson('/api/v1/reports/received')->json('data.rows'));
        $this->assertCount(1, $today, 'today only by default: just the cash received today');
        $this->assertSame('pending_verification', $today->first()['status']);
        $this->assertCount(2, collect($this->getJson('/api/v1/reports/received?branch_id='.$this->admin->branch_id)->json('data.rows'))->whereIn('status', ['completed', 'reversed']));
        $branchwise = collect($this->getJson('/api/v1/reports/branchwise')->assertOk()->json('data.rows'))->firstWhere('branch_id', $this->admin->branch_id);
        $this->assertEquals(20000, $branchwise['received']);
    }

    public function test_expense_report_lists_reversed_expenses_without_counting_them(): void
    {
        $companyId = $this->admin->company_id;
        $branchId = $this->admin->branch_id;
        app(Ledger::class)->transfer($companyId, ['account' => Account::Company], ['account' => Account::PettyCash, 'branch' => $branchId], 50000, 'PETTY CASH');
        $type = ExpenseType::create(['company_id' => $companyId, 'scope' => 'branch', 'name' => 'RENT']);
        $request = fn (float $amount) => ExpenseRequest::create([
            'company_id' => $companyId, 'scope' => 'branch', 'branch_id' => $branchId, 'expense_type_id' => $type->id,
            'amount' => $amount, 'description' => 'rent', 'status' => 'pending', 'request_date' => today(),
        ]);
        app(ExpenseApproval::class)->accept($request(8000), $this->admin, 8000, null);
        $mistake = app(ExpenseApproval::class)->accept($request(5000), $this->admin, 5000, null);
        app(Ledger::class)->reverse($mistake->journalEntry, 'DEVFLOW duplicate');

        $data = $this->getJson('/api/v1/reports/financial/expenses?branch_id=all&from='.today()->toDateString().'&to='.today()->toDateString())->assertOk()->json('data');

        $this->assertCount(2, $data['rows']);
        $this->assertEquals(8000, $data['total']);
        $this->assertSame(1, $data['reversed_count']);
        $this->assertEquals(5000, $data['reversed_total']);
        $this->assertTrue(collect($data['rows'])->firstWhere('id', $mistake->id)['reversed']);
        $this->assertSame(0, $data['mis_tagged_count']);

        $daily = $this->getJson('/api/v1/reports/daily?from='.today()->toDateString().'&to='.today()->toDateString())->assertOk()->json('data');
        $this->assertEquals(8000, $daily['out']['EXPENSES']);
    }
}
