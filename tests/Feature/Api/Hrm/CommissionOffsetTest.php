<?php

namespace Tests\Feature\Api\Hrm;

use App\Enums\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\BranchPeriodResult;
use App\Models\Employee;
use App\Models\HrmSetting;
use App\Models\Zone;
use App\Services\DividendService;
use App\Services\Hrm\CommissionEngine;
use App\Services\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec §15 + §59: the offset settled in the period is excluded from the commission base and added back before the
 * dividend / reinvestment split.
 */
class CommissionOffsetTest extends TestCase
{
    use RefreshDatabase;

    private Employee $admin;

    private CarbonImmutable $july;

    private Branch $branch;

    private Employee $staff;

    private Employee $zoneManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-08-03 09:00:00'));
        $this->admin = $this->signInAdmin();
        $this->july = CarbonImmutable::parse('2026-07-01');
        HrmSetting::forCompany($this->admin->company_id)->update(['commission_pool_percent' => 10, 'zone_override_percent' => 5]);
        $this->admin->company->update(['dividend_shareholder_percent' => 30, 'dividend_reinvest_percent' => 70]);

        $zone = Zone::create(['company_id' => $this->admin->company_id, 'name' => 'Lake']);
        $this->branch = Branch::factory()->create(['company_id' => $this->admin->company_id, 'zone_id' => $zone->id]);
        $this->staff = $this->employee(600000, 'branch');
        $this->zoneManager = $this->employee(800000, 'zone_manager', ['zone_id' => $zone->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function employee(int $salary, string $type, array $attributes = []): Employee
    {
        $employee = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id] + $attributes);
        $employee->salaryInfo()->create(['salary' => $salary, 'salary_type' => $type, 'commission_eligible' => true, 'payment_method' => 'bank', 'account_name' => 'NMB', 'account_number' => '1', 'fee' => 0]);

        return $employee;
    }

    /**
     * July closed with 10,000,000 distributable profit in the branch PROFIT ACCOUNT, of which 3,000,000 is offset.
     */
    private function closeJuly(float $offset): BranchPeriodResult
    {
        app(Ledger::class)->journal($this->admin->company_id, 'July profit closed', [
            ['account' => Account::Interest, 'branch' => $this->branch->id, 'debit' => 10000000],
            ['account' => Account::RetainedProfit, 'branch' => $this->branch->id, 'credit' => 10000000],
        ]);
        $period = AccountingPeriod::create(['company_id' => $this->admin->company_id, 'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'status' => AccountingPeriod::STATUS_CLOSED]);

        return BranchPeriodResult::create([
            'accounting_period_id' => $period->id, 'branch_id' => $this->branch->id, 'net_profit' => 10000000,
            'distributable_profit' => 10000000, 'offset_amount' => $offset, 'commission_eligible' => true,
        ]);
    }

    public function test_section_59_offset_is_excluded_from_commission_and_added_back_for_distribution(): void
    {
        $this->closeJuly(3000000);
        $engine = app(CommissionEngine::class);

        $preview = collect($engine->report($this->admin->company_id, $this->july)['branches'])->firstWhere('branch_id', $this->branch->id);
        $this->assertEquals([10000000, 3000000, 7000000, 700000], [$preview['distributable_profit'], $preview['offset_amount'], $preview['commission_base'], $preview['pool_amount']]);

        $this->postJson('/api/v1/hrm/commission/calculate', ['period' => '2026-07'])->assertOk();

        $report = $this->getJson('/api/v1/hrm/commission?period=2026-07')->assertOk()->json('data');
        $branch = collect($report['branches'])->firstWhere('branch_id', $this->branch->id);
        $this->assertEquals(3000000, $branch['offset_amount']);
        $this->assertEquals(7000000, $branch['commission_base']);
        $this->assertEquals(700000, $branch['pool_amount']);
        $this->assertEquals(35000, $branch['zone_manager_amount']);
        $this->assertEquals(665000, $branch['staff_pool_amount']);
        $this->assertEquals(665000, collect($branch['staff'])->firstWhere('employee_id', $this->staff->id)['amount']);
        $this->assertEquals(35000, collect($report['zone_managers'])->firstWhere('employee_id', $this->zoneManager->id)['amount']);
        $this->assertEquals(700000, $report['total_commission']);
        $this->assertEquals(0, $report['total_returned_to_profit']);

        // Distribution base: (7,000,000 − 700,000) + 3,000,000 offset added back = 10,000,000 − 700,000 = 9,300,000.
        $dividends = app(DividendService::class);
        $available = $dividends->availableProfit($this->admin->company_id, $this->july);
        $this->assertEquals([10000000, 700000, 9300000, 9300000], [$available['distributable_profit'], $available['commission_amount'], $available['base_amount'], $available['profit_available']]);

        $preview = $dividends->preview($this->admin->company_id, $this->july);
        $this->assertEquals(9300000, $preview['base_amount']);
        $this->assertEquals(2790000, $preview['dividend_pool']);
        $this->assertEquals(6510000, $preview['reinvestment_amount']);
    }

    public function test_a_period_calculated_before_the_offset_rule_keeps_its_stored_commission(): void
    {
        $result = $this->closeJuly(0);
        $engine = app(CommissionEngine::class);
        $engine->calculate($this->admin->company_id, $this->july, $this->admin);

        // An offset stored on the result afterwards does not recompute the calculated history.
        $result->update(['offset_amount' => 3000000]);

        $branch = collect($engine->report($this->admin->company_id, $this->july)['branches'])->firstWhere('branch_id', $this->branch->id);
        $this->assertEquals(1000000, $branch['pool_amount']);
        $this->assertEquals(10000000, $branch['commission_base']);
        $this->assertEquals(0, $branch['offset_amount']);
        $this->assertEquals(950000, collect($branch['staff'])->firstWhere('employee_id', $this->staff->id)['amount']);
    }
}
