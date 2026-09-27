<?php

namespace Tests\Feature\Accounting;

use App\Enums\Account;
use App\Enums\TransactionType;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\CommissionAllocation;
use App\Models\Employee;
use App\Models\HrmSetting;
use App\Models\JournalEntry;
use App\Models\Zone;
use App\Services\DividendService;
use App\Services\Hrm\CommissionEngine;
use App\Services\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit tests for already-implemented profit decisions (the closed-month declaration rule and the legacy reserve closing are
 * covered by ProfitChainTest::test_declarations_need_a_closed_month and
 * ::test_closing_moves_legacy_reserve_to_interest_reserve_and_insurance_to_insurance_reserve):
 *  (c) C4: a commission pool with no eligible staff is not allocated at all — not even the zone manager's 5 % — no journal, the
 *      Profit Account keeps it and the dividend base subtracts only the commission actually allocated; without a zone manager
 *      staff receive only 95 %;
 *  (d) rule 5: while commission is not yet calculated nothing is subtracted from the dividend base or cap; only calculated
 *      (stored) commission is.
 */
class ProfitDecisionsTest extends TestCase
{
    use RefreshDatabase;

    private Employee $admin;

    private CarbonImmutable $month;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-02 09:00:00'));
        $this->admin = $this->signInAdmin();
        $this->month = CarbonImmutable::parse('2026-06-01');
        HrmSetting::forCompany($this->admin->company_id)->update(['commission_pool_percent' => 10, 'zone_override_percent' => 5]);
    }

    public function test_a_pool_without_eligible_staff_stays_in_profit_and_is_not_subtracted_from_the_dividend_base(): void
    {
        $this->closedJune(100000, 100000);
        $engine = app(CommissionEngine::class);

        $rows = $engine->calculate($this->admin->company_id, $this->month, $this->admin);

        $this->assertCount(0, $rows);
        $this->assertSame(0, CommissionAllocation::count());
        $this->assertSame(0, JournalEntry::where('transaction_type', TransactionType::CommissionAllocation->value)->count());
        $this->assertBalance(100000, Account::RetainedProfit, $this->admin->branch_id);
        $this->assertBalance(0, Account::CommissionPayable, allBranches: true);
        $branch = collect($engine->report($this->admin->company_id, $this->month)['branches'])->firstWhere('branch_id', $this->admin->branch_id);
        $this->assertEquals([10000, 10000], [$branch['pool_amount'], $branch['unallocated_amount']]);

        $preview = app(DividendService::class)->preview($this->admin->company_id, $this->month);
        $this->assertEquals([100000, 0, 100000, 100000], [$preview['distributable_profit'], $preview['commission_amount'], $preview['base_amount'], $preview['profit_available']]);
    }

    public function test_a_branch_without_staff_returns_its_whole_pool_to_profit_and_the_zone_manager_gets_nothing(): void
    {
        $zone = Zone::create(['company_id' => $this->admin->company_id, 'name' => 'LAKE']);
        $this->admin->branch->update(['zone_id' => $zone->id]);
        $manager = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => Branch::factory()->create(['company_id' => $this->admin->company_id])->id, 'zone_id' => $zone->id]);
        $manager->salaryInfo()->create(['salary' => 500000, 'salary_type' => 'zone_manager', 'commission_eligible' => true, 'payment_method' => 'bank', 'account_name' => 'NMB', 'account_number' => '1', 'fee' => 0]);
        $this->closedJune(100000, 100000);

        app(CommissionEngine::class)->calculate($this->admin->company_id, $this->month, $this->admin);

        $this->assertEquals(0, round((float) CommissionAllocation::where('employee_id', $manager->id)->sum('amount'), 2), 'C4: no staff → the zone manager share returns to profit too');
        $this->assertSame(0, JournalEntry::where('transaction_type', TransactionType::CommissionAllocation->value)->count());
        $this->assertBalance(0, Account::CommissionPayable, allBranches: true);
        $this->assertBalance(100000, Account::RetainedProfit, $this->admin->branch_id, 'the entire pool (10,000) stays in profit');
        $report = app(CommissionEngine::class)->report($this->admin->company_id, $this->month);
        $branch = collect($report['branches'])->firstWhere('branch_id', $this->admin->branch_id);
        $this->assertEquals([10000, 0, 10000, 0, 0], [$branch['returned_to_profit_amount'], $branch['zone_manager_amount'], $branch['returned_no_staff_amount'], $branch['returned_no_zone_manager_amount'], $report['total_commission']]);
        $this->assertSame(CommissionEngine::RULE_PROFIT_ALLOCATION, $report['rule'], 'zero-amount rows of a calculated month are not legacy');
        $preview = app(DividendService::class)->preview($this->admin->company_id, $this->month);
        $this->assertEquals([0, 100000], [$preview['commission_amount'], $preview['base_amount']]);
    }

    public function test_the_dividend_cap_subtracts_only_commission_already_calculated(): void
    {
        $staff = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id]);
        $staff->salaryInfo()->create(['salary' => 100000, 'salary_type' => 'branch', 'commission_eligible' => true, 'payment_method' => 'bank', 'account_name' => 'NMB', 'account_number' => '1', 'fee' => 0]);
        // Distributable 100,000 but only 95,000 left in the Profit Account.
        $this->closedJune(100000, 95000);

        $profit = app(DividendService::class)->availableProfit($this->admin->company_id, $this->month);
        $this->assertFalse($profit['commission_calculated']);
        $this->assertEquals([0, 100000, 95000, 95000], [$profit['commission_amount'], $profit['base_amount'], $profit['profit_account_balance'], $profit['profit_available']], 'rule 5: uncalculated commission never reduces the base or the cap');

        app(CommissionEngine::class)->calculate($this->admin->company_id, $this->month, $this->admin);
        $profit = app(DividendService::class)->availableProfit($this->admin->company_id, $this->month);
        $this->assertTrue($profit['commission_calculated']);
        $this->assertEquals([9500, 90500, 85500, 85500], [$profit['commission_amount'], $profit['base_amount'], $profit['profit_account_balance'], $profit['profit_available']], 'C4: no zone manager → staff 95 %, the 5 % stays in profit');
    }

    private function closedJune(float $distributable, float $profitAccount): void
    {
        $branchId = $this->admin->branch_id;
        app(Ledger::class)->journal($this->admin->company_id, 'PROFIT', [
            ['account' => Account::Interest, 'branch' => $branchId, 'debit' => $profitAccount],
            ['account' => Account::RetainedProfit, 'branch' => $branchId, 'credit' => $profitAccount],
        ], null, CarbonImmutable::parse('2026-06-30'));
        $period = AccountingPeriod::create(['company_id' => $this->admin->company_id, 'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'status' => AccountingPeriod::STATUS_CLOSED, 'closed_at' => now()]);
        $period->results()->create(['branch_id' => $branchId, 'gross_profit' => $distributable, 'net_profit' => $distributable, 'distributable_profit' => $distributable, 'commission_eligible' => true]);
    }

    private function assertBalance(float $expected, Account $account, ?int $branch = null, string $message = '', bool $allBranches = false): void
    {
        $this->assertEqualsWithDelta($expected, app(Ledger::class)->balance($this->admin->company_id, $account, $branch, allBranches: $allBranches), 0.001, $message ?: $account->value);
    }
}
