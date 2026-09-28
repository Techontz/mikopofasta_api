<?php

namespace Tests\Feature\Api\Reports\Financial;

use App\Enums\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\ExpenseType;
use App\Services\Ledger;
use App\Services\PeriodClose;
use App\Services\Reports\Financial\CashFlowReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Master Cash Flow, Daily Position, Branch P&L, Consolidated P&L, Branch Ranking and Balance Sheet
 * computed from ledger fixtures.
 */
class FinancialLedgerReportsTest extends TestCase
{
    use RefreshDatabase;

    private Employee $admin;

    private Branch $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
        $this->second = Branch::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'SECOND']);
        $this->admin->branch->update(['name' => 'FIRST']);
        $this->seedLedger();
    }

    public function test_master_cash_flow_categorises_inflows_outflows_and_keeps_a_running_balance(): void
    {
        $data = $this->getJson('/api/v1/reports/financial/cash-flow?branch_id=all&from=2026-07-01&to=2026-08-31')
            ->assertOk()->json('data');

        $this->assertEquals(0, $data['opening']);
        $inflows = collect($data['inflows'])->pluck('amount', 'key');
        $outflows = collect($data['outflows'])->pluck('amount', 'key');
        $this->assertEquals(1000000, $inflows['funding']);
        $this->assertEquals(50000, $inflows['loan_principal']);
        $this->assertEquals(10000 + 100000 + 20000, $inflows['interest']);
        $this->assertEquals(10000 + 20000, $inflows['fees']);
        $this->assertEquals(5000, $inflows['penalty']);
        $this->assertEquals(200000, $outflows['loan_principal']);
        $this->assertEquals(40000 + 15000, $outflows['branch_expense']);
        $this->assertEquals(1215000, $data['total_inflow']);
        $this->assertEquals(262000, $data['total_outflow']);
        $this->assertEquals(7000, $outflows['hq_expense']);
        $this->assertArrayNotHasKey('transfer', $inflows->all(), 'internal transfers are not flows company-wide');

        $this->assertEquals($data['opening'] + $data['total_inflow'] - $data['total_outflow'], $data['closing']);
        $this->assertEquals($data['closing'], end($data['transactions'])['balance']);
        $this->assertEquals(50000, $data['suspense_held'], 'unmatched suspense money is not available cash');
        $this->assertEquals($data['closing'] + 50000, $data['cash_held']);

        $cashAccounts = collect(Account::cases())->filter(fn (Account $account): bool => $account->type() === 'asset' && ! in_array($account, CashFlowReport::NON_CASH_ASSETS, true));
        $ledgerCash = $cashAccounts->sum(fn (Account $account): float => app(Ledger::class)->balance($this->admin->company_id, $account, allBranches: true));
        $this->assertEquals(round($ledgerCash - 50000, 2), $data['closing']);

        $row = collect($data['transactions'])->firstWhere('description', 'Expenses: RENT');
        $this->assertEquals(15000, $row['outflow']);
        $this->assertSame('FIRST', $row['branch']);
        $this->assertSame($this->admin->full_name, $row['approved_by']);
    }

    public function test_cash_flow_for_one_branch_shows_transfers_and_opening_balance(): void
    {
        $data = $this->getJson("/api/v1/reports/financial/cash-flow?branch_id={$this->admin->branch_id}&from=2026-08-01&to=2026-08-31")
            ->assertOk()->json('data');

        // July: +500,000 transfer in, −200,000 disbursement, +10,000 fee.
        $this->assertEquals(310000, $data['opening']);
        $this->assertEquals(310000 + 175000 - 15000, $data['closing']);
        $this->assertEquals(175000, $data['total_inflow']);
        $this->assertEquals(15000, $data['total_outflow']);

        $july = $this->getJson("/api/v1/reports/financial/cash-flow?branch_id={$this->admin->branch_id}&from=2026-07-01&to=2026-07-31")->json('data');
        $this->assertEquals(500000, collect($july['inflows'])->firstWhere('key', 'transfer')['amount']);
        $this->assertSame('Loan disbursement', collect($july['outflows'])->firstWhere('key', 'loan_principal')['label']);
    }

    public function test_daily_position_per_day_and_per_branch(): void
    {
        $data = $this->getJson('/api/v1/reports/financial/daily-position?branch_id=all&from=2026-08-10&to=2026-08-12')->assertOk()->json('data');

        $this->assertCount(3, $data['days']);
        $day = collect($data['days'])->firstWhere('date', '2026-08-10');
        $this->assertEquals(175000, $day['cash_in']);
        $this->assertEquals(0, $day['cash_out']);
        $this->assertEquals($day['opening'] + 175000, $day['closing']);
        $this->assertEquals($data['opening'] + $data['net'], $data['closing']);

        $branches = collect($data['branches'])->keyBy('branch');
        $this->assertEquals(['HQ (COMPANY)', 'FIRST', 'SECOND'], $branches->keys()->all());
        $this->assertEquals(175000, $branches['FIRST']['net']);
        $this->assertEquals(round($branches->sum('closing'), 2), $data['closing']);
    }

    public function test_branch_pnl_matches_month_end_results_and_ledger_excluding_closing_entries(): void
    {
        $periodClose = app(PeriodClose::class);
        $july = $periodClose->calculate($this->admin->company_id, CarbonImmutable::parse('2026-07-01'));
        $periodClose->close($july, $this->admin);
        $august = $periodClose->calculate($this->admin->company_id, CarbonImmutable::parse('2026-08-01'));
        $periodClose->close($august, $this->admin);

        $rows = collect($this->getJson('/api/v1/reports/financial/branch-pnl?branch_id=all&from=2026-08-01&to=2026-08-31')->assertOk()->json('data.rows'))->keyBy('branch');

        foreach ($august->results as $result) {
            $row = $rows[$result->branch->name];
            foreach (['interest_income', 'reserve_amount', 'fee_income', 'penalty_income', 'total_income', 'expenses', 'gross_profit', 'loss_brought_forward', 'net_profit', 'loss_carried_forward', 'hq_hold_amount', 'distributable_profit'] as $column) {
                $this->assertEquals((float) $result->{$column}, $row[$column], "{$result->branch->name} {$column}");
            }
        }

        $this->assertEquals(90000, $rows['FIRST']['interest_income']);
        $this->assertEquals(115000, $rows['FIRST']['total_income']);
        $this->assertEquals(100000, $rows['FIRST']['net_profit']);
        $this->assertEquals(2000, $rows['FIRST']['hq_hold_amount']);
        // SECOND lost 30,000 in July; August profit 20,000 offsets part of it.
        $this->assertEquals(30000, $rows['SECOND']['loss_brought_forward']);
        $this->assertEquals(-10000, $rows['SECOND']['net_profit']);
        $this->assertEquals(10000, $rows['SECOND']['loss_carried_forward']);
        $this->assertEquals(-7000, $rows['HQ (COMPANY)']['gross_profit']);

        $consolidated = $this->getJson('/api/v1/reports/financial/profit-loss?branch_id=all&from=2026-07-01&to=2026-08-31')->assertOk()->json('data');
        $ledger = app(Ledger::class);
        $from = CarbonImmutable::parse('2026-07-01');
        $to = CarbonImmutable::parse('2026-08-31');
        $income = collect([Account::InterestIncome, Account::FeeIncome, Account::PenaltyIncome, Account::RecoveryIncome, Account::InsuranceIncome])
            ->sum(fn (Account $account): float => $ledger->movement($this->admin->company_id, $account, $from, $to) - $this->closingMovement($account, true));
        $expenses = collect(PeriodClose::EXPENSE_ACCOUNTS)
            ->sum(fn (Account $account): float => $ledger->movement($this->admin->company_id, $account, $from, $to) - $this->closingMovement($account, true));

        $this->assertEquals(round($income, 2), $consolidated['gross_income']);
        $this->assertEquals(round($expenses, 2), $consolidated['total_expenses']);
        $this->assertEquals(10000, $consolidated['reserve_amount']);
        $this->assertEquals(round($income - 10000 - $expenses, 2), $consolidated['net_profit']);
        $this->assertEquals(165000 - 10000 - 62000, $consolidated['net_profit']);
        $this->assertSame(['2026-07', '2026-08'], array_column($consolidated['months'], 'month'));
        $this->assertEquals($consolidated['net_profit'], round(array_sum(array_column($consolidated['months'], 'net_profit')), 2));
        $rent = collect($consolidated['expenses'])->firstWhere('key', Account::OperatingExpense->value)['breakdown'];
        $this->assertEquals(15000, collect($rent)->firstWhere('label', 'RENT')['amount']);
    }

    public function test_branch_ranking_orders_best_to_worst_with_efficiency_metrics(): void
    {
        Employee::factory()->count(2)->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->second->id, 'status' => 'active']);

        $rows = $this->getJson('/api/v1/reports/financial/branch-ranking?branch_id=all&from=2026-07-01&to=2026-08-31')->assertOk()->json('data');

        $this->assertSame(['FIRST', 'SECOND'], array_column($rows, 'branch'));
        $this->assertSame([1, 2], array_column($rows, 'rank'));
        $this->assertSame('BEST', $rows[0]['performance']);
        $this->assertSame('WORST', $rows[1]['performance']);
        $this->assertEquals(110000, $rows[0]['net_profit']);
        $this->assertEquals(round(110000 / 15000, 2), $rows[0]['profit_expense_ratio']);
        $this->assertEquals(2, $rows[1]['staff']);
        $this->assertEquals(15000, $rows[1]['revenue_per_staff']);
    }

    public function test_balance_sheet_balances_company_wide_and_per_branch_before_and_after_close(): void
    {
        $sheet = $this->getJson('/api/v1/reports/financial/balance-sheet?branch_id=all&to=2026-08-31')->assertOk()->json('data');

        $this->assertTrue($sheet['balanced']);
        $this->assertEquals($sheet['total_assets'], $sheet['total_liabilities_equity']);
        $this->assertEquals(0, $sheet['difference']);
        $portfolio = collect($sheet['assets'])->firstWhere('group', 'Loan portfolio');
        $this->assertEquals(150000, $portfolio['total']);
        $this->assertEquals(50000, collect($sheet['liabilities'])->firstWhere('key', Account::Suspense->value)['amount']);
        $equity = collect($sheet['equity'])->keyBy('key');
        $this->assertEquals(1000000, $equity[Account::Capital->value]['amount']);
        $this->assertEquals(165000 - 10000 - 62000 + 10000, $equity['current_earnings']['amount']);
        $this->assertArrayNotHasKey('inter_branch', $equity->all());

        $branch = $this->getJson("/api/v1/reports/financial/balance-sheet?branch_id={$this->second->id}&to=2026-08-31")->assertOk()->json('data');
        $this->assertTrue($branch['balanced']);
        $this->assertNotNull(collect($branch['equity'])->firstWhere('key', 'inter_branch'));

        $periodClose = app(PeriodClose::class);
        $periodClose->close($periodClose->calculate($this->admin->company_id, CarbonImmutable::parse('2026-07-01')), $this->admin);
        $closed = $this->getJson('/api/v1/reports/financial/balance-sheet?branch_id=all&to=2026-07-31')->assertOk()->json('data');
        $this->assertTrue($closed['balanced']);
        $this->assertEquals(0, collect($closed['equity'])->firstWhere('key', 'current_earnings')['amount']);
    }

    public function test_permission_and_branch_scope(): void
    {
        $role = $this->admin->company->roles()->where('key', 'loan_officer')->firstOrFail();
        $officer = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'role_id' => $role->id]);
        foreach (['cash-flow', 'daily-position', 'branch-pnl', 'branch-ranking', 'profit-loss', 'balance-sheet'] as $report) {
            $this->actingAs($officer)->getJson("/api/v1/reports/financial/{$report}")->assertForbidden();
        }

        $managerRole = $this->admin->company->roles()->where('key', 'branch_manager')->firstOrFail();
        $managerRole->permissions()->firstOrCreate(['permission' => 'reports.financial']);
        $manager = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->second->id, 'role_id' => $managerRole->id]);

        $rows = $this->actingAs($manager)->getJson('/api/v1/reports/financial/branch-pnl?branch_id=all&from=2026-08-01&to=2026-08-31')->assertOk()->json('data.rows');
        $this->assertSame(['SECOND'], array_column($rows, 'branch'));
        $this->actingAs($manager)->getJson("/api/v1/reports/financial/cash-flow?branch_id={$this->admin->branch_id}")->assertForbidden();
        $this->actingAs($manager)->getJson('/api/v1/reports/financial/balance-sheet?branch_id=hq')->assertForbidden();

        $flow = $this->actingAs($manager)->getJson('/api/v1/reports/financial/cash-flow?branch_id=all&from=2026-07-01&to=2026-08-31')->assertOk()->json('data');
        $this->assertEmpty(collect($flow['transactions'])->where('branch', 'FIRST')->all());
    }

    /**
     * Movement of an income/expense account posted by month-end closing entries (to exclude it).
     */
    private function closingMovement(Account $account, bool $inflow): float
    {
        return (float) DB::table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('accounts.key', $account->value)
            ->where('journal_entries.source_type', (new AccountingPeriod)->getMorphClass())
            ->sum('journal_lines.'.($account->isDebitNormal() === $inflow ? 'debit' : 'credit'));
    }

    /**
     * July: capital 1,000,000 → HQ; HQ funds FIRST 500,000 and SECOND 300,000; FIRST disburses 200,000 (fee 10,000);
     * SECOND earns 10,000 and spends 40,000 (loss 30,000); HQ expense 7,000 (August).
     * August: FIRST repayment 50,000 principal + 100,000 interest (10,000 reserve) + 20,000 fee + 5,000 penalty,
     * expense RENT 15,000; SECOND earns 20,000; 50,000 unmatched receipt into suspense.
     */
    private function seedLedger(): void
    {
        $ledger = app(Ledger::class);
        $company = $this->admin->company_id;
        $first = $this->admin->branch_id;
        $second = $this->second->id;
        $date = fn (string $value): CarbonImmutable => CarbonImmutable::parse($value);
        $rent = ExpenseType::create(['company_id' => $company, 'scope' => 'branch', 'name' => 'RENT']);

        $ledger->openingBalance($company, Account::Company, 1000000, 'CAPITAL', date: $date('2026-07-01'));
        $ledger->transfer($company, ['account' => Account::Company], ['account' => Account::Principal, 'branch' => $first], 500000, 'FLOAT FIRST', date: $date('2026-07-02'));
        $ledger->transfer($company, ['account' => Account::Company], ['account' => Account::Principal, 'branch' => $second], 300000, 'FLOAT SECOND', date: $date('2026-07-02'));
        $ledger->journal($company, 'LOAN DISBURSEMENT L1', [
            ['account' => Account::LoanReceivable, 'branch' => $first, 'debit' => 200000],
            ['account' => Account::Principal, 'branch' => $first, 'credit' => 200000],
            ['account' => Account::LoanFee, 'branch' => $first, 'debit' => 10000],
            ['account' => Account::FeeIncome, 'branch' => $first, 'credit' => 10000],
        ], null, $date('2026-07-05'), $first);
        $ledger->journal($company, 'LOAN RETURN S1', [
            ['account' => Account::Interest, 'branch' => $second, 'debit' => 10000],
            ['account' => Account::InterestIncome, 'branch' => $second, 'credit' => 10000],
        ], null, $date('2026-07-10'), $second);
        $ledger->transfer($company, ['account' => Account::Interest, 'branch' => $second], ['account' => Account::OperatingExpense, 'branch' => $second], 40000, 'Expenses: SECOND', date: $date('2026-07-20'));

        $ledger->journal($company, 'LOAN RETURN L1', [
            ['account' => Account::Principal, 'branch' => $first, 'debit' => 50000],
            ['account' => Account::LoanReceivable, 'branch' => $first, 'credit' => 50000],
            ['account' => Account::Interest, 'branch' => $first, 'debit' => 90000],
            ['account' => Account::Reserve, 'branch' => $first, 'debit' => 10000],
            ['account' => Account::InterestIncome, 'branch' => $first, 'credit' => 100000],
            ['account' => Account::LoanFee, 'branch' => $first, 'debit' => 20000],
            ['account' => Account::FeeIncome, 'branch' => $first, 'credit' => 20000],
            ['account' => Account::Penalty, 'branch' => $first, 'debit' => 5000],
            ['account' => Account::PenaltyIncome, 'branch' => $first, 'credit' => 5000],
        ], null, $date('2026-08-10'), $first, $this->admin);
        $ledger->transfer($company, ['account' => Account::Interest, 'branch' => $first], ['account' => Account::OperatingExpense, 'branch' => $first, 'expense_type' => $rent->id], 15000, 'Expenses: RENT', date: $date('2026-08-20'));
        $ledger->journal($company, 'LOAN RETURN S2', [
            ['account' => Account::Interest, 'branch' => $second, 'debit' => 20000],
            ['account' => Account::InterestIncome, 'branch' => $second, 'credit' => 20000],
        ], null, $date('2026-08-15'), $second);
        $ledger->transfer($company, ['account' => Account::Company], ['account' => Account::OperatingExpense], 7000, 'Expenses: HQ', date: $date('2026-08-21'));
        $ledger->journal($company, 'SUSPENSE VODACOM T1', [
            ['account' => Account::Bank, 'debit' => 50000],
            ['account' => Account::Suspense, 'branch' => $first, 'credit' => 50000],
        ], null, $date('2026-08-25'), $first);
    }
}
