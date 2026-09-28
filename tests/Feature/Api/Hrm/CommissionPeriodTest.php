<?php

namespace Tests\Feature\Api\Hrm;

use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\BranchPeriodResult;
use App\Models\CommissionAllocation;
use App\Models\Employee;
use App\Models\HrmSetting;
use App\Models\PayrollRun;
use App\Models\Zone;
use App\Services\Hrm\CommissionEngine;
use App\Services\Hrm\PayrollEngine;
use App\Services\PeriodClose;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Commission requires a month CLOSED by the month-end close, and a zero profit is "no distributable profit", not a loss.
 */
class CommissionPeriodTest extends TestCase
{
    use RefreshDatabase;

    private Employee $admin;

    private CarbonImmutable $month;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
        $this->month = CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth();
        HrmSetting::forCompany($this->admin->company_id)->update(['commission_pool_percent' => 10, 'zone_override_percent' => 5, 'staff_fund_percent' => 10]);
    }

    private function engine(): CommissionEngine
    {
        return app(CommissionEngine::class);
    }

    private function branch(?Zone $zone = null): Branch
    {
        return Branch::factory()->create(['company_id' => $this->admin->company_id, 'zone_id' => $zone?->id]);
    }

    private function zone(string $name = 'Lake'): Zone
    {
        return Zone::create(['company_id' => $this->admin->company_id, 'name' => $name]);
    }

    private function staff(Branch $branch, int $salary, string $type = 'branch', array $attributes = []): Employee
    {
        $employee = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $branch->id] + $attributes);
        $employee->salaryInfo()->create(['salary' => $salary, 'salary_type' => $type, 'commission_eligible' => $type !== 'hq', 'payment_method' => 'bank', 'account_name' => 'NMB', 'account_number' => '1', 'fee' => 0]);

        return $employee;
    }

    private function period(string $status, ?CarbonImmutable $month = null): AccountingPeriod
    {
        $month ??= $this->month;

        return AccountingPeriod::create(['company_id' => $this->admin->company_id, 'period_start' => $month->toDateString(), 'period_end' => $month->endOfMonth()->toDateString(), 'status' => $status]);
    }

    /**
     * A branch result as the month-end close writes it (net profit − 2% HQ hold = distributable).
     */
    private function branchResult(AccountingPeriod $period, Branch $branch, float $netProfit, float $lossBroughtForward = 0): BranchPeriodResult
    {
        $hold = $netProfit > 0 ? round($netProfit * 0.02, 2) : 0.0;
        $distributable = $netProfit > 0 ? round($netProfit - $hold, 2) : 0.0;

        return BranchPeriodResult::create([
            'accounting_period_id' => $period->id, 'branch_id' => $branch->id,
            'gross_profit' => round($netProfit + $lossBroughtForward, 2), 'loss_brought_forward' => $lossBroughtForward, 'net_profit' => $netProfit,
            'loss_carried_forward' => $netProfit < 0 ? -$netProfit : 0, 'hq_hold_percent' => 2, 'hq_hold_amount' => $hold,
            'distributable_profit' => $distributable, 'commission_eligible' => $distributable > 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function reportBranch(array $report, Branch $branch): array
    {
        return collect($report['branches'])->firstWhere('branch_id', $branch->id);
    }

    public function test_an_open_period_cannot_calculate_commission_even_when_its_profit_is_calculated(): void
    {
        $branch = $this->branch();
        $this->staff($branch, 300000);

        // Accounting → Month End & Profit → "Calculate profit": results exist but the period stays OPEN.
        $period = app(PeriodClose::class)->calculate($this->admin->company_id, $this->month);
        $this->assertSame(AccountingPeriod::STATUS_OPEN, $period->status);
        BranchPeriodResult::where('accounting_period_id', $period->id)->where('branch_id', $branch->id)->update(['net_profit' => 500000, 'distributable_profit' => 490000, 'commission_eligible' => true]);

        $this->assertNull($this->engine()->closedPeriod($this->admin->company_id, $this->month));

        $report = $this->engine()->report($this->admin->company_id, $this->month);
        $this->assertFalse($report['period_closed']);
        $this->assertFalse($report['can_calculate']);
        $this->assertSame("The period {$this->month->format('F Y')} is not closed. Close the month before calculating commission.", $report['calculate_blocked_reason']);
        $this->assertSame([], $report['branches']);

        try {
            $this->engine()->calculate($this->admin->company_id, $this->month);
            $this->fail('Commission was calculated for an open period.');
        } catch (ValidationException $exception) {
            $this->assertSame(["The period {$this->month->format('F Y')} is not closed. Close the month before calculating commission."], $exception->errors()['period']);
        }
        $this->assertSame(0, CommissionAllocation::count());

        // Salary sheet and payroll generation follow the same definition: no commission from an open month.
        $this->getJson("/api/v1/hrm/payroll?period={$this->month->format('Y-m')}")->assertOk()->assertJsonPath('data.period_closed', false);
        $run = app(PayrollEngine::class)->generate($this->admin->company_id, $this->month, $this->admin);
        $this->assertSame('period_not_closed', $run->commission_status);
        $this->assertEquals(0, $run->items()->sum('commission'));
    }

    public function test_a_period_closed_by_the_month_end_close_can_calculate_commission(): void
    {
        $branch = $this->branch();
        $staff = $this->staff($branch, 300000);
        $period = app(PeriodClose::class)->calculate($this->admin->company_id, $this->month);

        $closed = app(PeriodClose::class)->close($period, $this->admin);
        $this->assertTrue($closed->isClosed());
        BranchPeriodResult::where('accounting_period_id', $closed->id)->where('branch_id', $branch->id)->update(['net_profit' => 500000, 'hq_hold_amount' => 10000, 'distributable_profit' => 490000, 'commission_eligible' => true]);

        $this->assertTrue($this->engine()->closedPeriod($this->admin->company_id, $this->month)->is($closed));
        $this->assertTrue($this->engine()->report($this->admin->company_id, $this->month)['can_calculate']);

        $rows = $this->engine()->calculate($this->admin->company_id, $this->month);

        $this->assertCount(1, $rows);
        $this->assertSame($staff->id, $rows->first()->employee_id);
        $this->assertEquals(46550, $rows->first()->amount, 'C4: pool 49,000; no zone manager → staff 95%');
        $this->getJson("/api/v1/hrm/payroll?period={$this->month->format('Y-m')}")->assertOk()->assertJsonPath('data.period_closed', true);
    }

    public function test_the_api_rejects_calculating_an_open_period_with_422(): void
    {
        $branch = $this->branch();
        $this->staff($branch, 300000);
        $period = $this->period(AccountingPeriod::STATUS_OPEN);
        $this->branchResult($period, $branch, 800000);
        $label = $this->month->format('F Y');

        $this->getJson("/api/v1/hrm/commission?period={$this->month->format('Y-m')}")->assertOk()
            ->assertJsonPath('data.period_closed', false)
            ->assertJsonPath('data.can_calculate', false)
            ->assertJsonPath('data.calculate_blocked_reason', "The period {$label} is not closed. Close the month before calculating commission.");

        $this->postJson('/api/v1/hrm/commission/calculate', ['period' => $this->month->format('Y-m')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period' => "The period {$label} is not closed. Close the month before calculating commission."]);

        // A month without any accounting period at all is open too.
        $next = $this->month->addMonthNoOverflow();
        $this->postJson('/api/v1/hrm/commission/calculate', ['period' => $next->format('Y-m')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period' => "The period {$next->format('F Y')} is not closed. Close the month before calculating commission."]);
        $this->assertSame(0, CommissionAllocation::count());
    }

    public function test_a_zero_profit_branch_has_no_distributable_profit_and_is_not_a_loss(): void
    {
        $zeroBranch = $this->branch();
        $lossBranch = $this->branch();
        $this->staff($zeroBranch, 300000);
        $previous = $this->period(AccountingPeriod::STATUS_CLOSED, $this->month->subMonthNoOverflow());
        $this->branchResult($previous, $zeroBranch, 0);
        $this->branchResult($previous, $lossBranch, -50000);

        // No activity this month: the zero branch stays at zero (nothing carried), the loss branch keeps its loss.
        $period = app(PeriodClose::class)->calculate($this->admin->company_id, $this->month);
        $zero = $period->results->firstWhere('branch_id', $zeroBranch->id);
        $loss = $period->results->firstWhere('branch_id', $lossBranch->id);
        $this->assertEquals(0, $zero->loss_brought_forward);
        $this->assertEquals(0, $zero->net_profit);
        $this->assertEquals(0, $zero->loss_carried_forward);
        $this->assertEquals(50000, $loss->loss_carried_forward);
        $period->update(['status' => AccountingPeriod::STATUS_CLOSED]);

        // A float that is zero at cent precision is zero too.
        BranchPeriodResult::whereKey($zero->id)->update(['net_profit' => 0.1 + 0.2 - 0.3]);

        $this->engine()->calculate($this->admin->company_id, $this->month);
        $report = $this->getJson("/api/v1/hrm/commission?period={$this->month->format('Y-m')}")->assertOk()->json('data');

        $branch = $this->reportBranch($report, $zeroBranch);
        $this->assertFalse($branch['eligible']);
        $this->assertSame(CommissionEngine::PROFIT_NONE, $branch['profit_status']);
        $this->assertSame('No distributable profit', $branch['blocked_reason']);
        $this->assertEquals(0, $branch['pool_amount']);
        $this->assertEquals(0, $branch['loss_carried_forward']);
        $this->assertEquals(0, array_sum(array_column($branch['staff'], 'amount')));
        // The zero branch and the admin's own (inactive) branch; neither is counted as a loss.
        $this->assertSame(2, $report['summary']['branches_no_profit']);
        $this->assertSame(CommissionEngine::PROFIT_NONE, $this->reportBranch($report, $this->admin->branch)['profit_status']);
        $this->assertSame(1, $report['summary']['branches_in_loss']);

        // The following month brings no loss forward from the zero branch.
        $following = app(PeriodClose::class)->calculate($this->admin->company_id, $this->month->addMonthNoOverflow());
        $this->assertEquals(0, $following->results->firstWhere('branch_id', $zeroBranch->id)->loss_brought_forward);
        $this->assertEquals(50000, $following->results->firstWhere('branch_id', $lossBranch->id)->loss_brought_forward);
    }

    public function test_a_negative_profit_branch_still_must_recover_its_loss(): void
    {
        $lossBranch = $this->branch();
        $recoveringBranch = $this->branch();
        $this->staff($lossBranch, 300000);
        $this->staff($recoveringBranch, 300000);
        $period = $this->period(AccountingPeriod::STATUS_CLOSED);
        $this->branchResult($period, $lossBranch, -120000.50);
        $this->branchResult($period, $recoveringBranch, -0.01, 40000);

        $report = $this->engine()->report($this->admin->company_id, $this->month);

        foreach ([$lossBranch, $recoveringBranch] as $branch) {
            $row = $this->reportBranch($report, $branch);
            $this->assertFalse($row['eligible']);
            $this->assertSame(CommissionEngine::PROFIT_LOSS, $row['profit_status']);
            $this->assertSame('Loss must be recovered before commission', $row['blocked_reason']);
            $this->assertEquals(0, $row['pool_amount']);
        }
        $this->assertEquals(120000.50, $this->reportBranch($report, $lossBranch)['loss_carried_forward']);
        $this->assertSame(['branches_eligible' => 0, 'branches_in_loss' => 2, 'branches_no_profit' => 0, 'total_pools' => 0.0], $report['summary']);
    }

    public function test_positive_profit_eligibility_and_salary_share_are_unchanged(): void
    {
        $branch = $this->branch();
        $a = $this->staff($branch, 300000);
        $b = $this->staff($branch, 100000);
        $hq = $this->staff($branch, 500000, 'hq');
        $period = $this->period(AccountingPeriod::STATUS_CLOSED);
        $this->branchResult($period, $branch, 0.01);
        $small = $this->reportBranch($this->engine()->report($this->admin->company_id, $this->month), $branch);
        $this->assertTrue($small['eligible']);
        $this->assertSame(CommissionEngine::PROFIT_ELIGIBLE, $small['profit_status']);

        BranchPeriodResult::where('branch_id', $branch->id)->update(['net_profit' => 1020408.16, 'hq_hold_amount' => 20408.16, 'distributable_profit' => 1000000, 'commission_eligible' => true]);
        $this->engine()->calculate($this->admin->company_id, $this->month);
        $row = $this->reportBranch($this->engine()->report($this->admin->company_id, $this->month), $branch);

        $this->assertTrue($row['eligible']);
        $this->assertNull($row['blocked_reason']);
        $this->assertEquals(100000, $row['pool_amount']);
        // C4: the branch has no zone manager — staff share 95 % (95,000) by salary and 5,000 returns to profit.
        $this->assertEquals(71250, collect($row['staff'])->firstWhere('employee_id', $a->id)['amount']);
        $this->assertEquals(23750, collect($row['staff'])->firstWhere('employee_id', $b->id)['amount']);
        $this->assertEquals([5000, 5000, 0], [$row['returned_to_profit_amount'], $row['returned_no_zone_manager_amount'], $row['returned_no_staff_amount']]);
        $this->assertNull(collect($row['staff'])->firstWhere('employee_id', $hq->id));
    }

    public function test_zone_manager_override_counts_a_zero_profit_branch_as_a_zero_pool(): void
    {
        $zone = $this->zone();
        $profitBranch = $this->branch($zone);
        $zeroBranch = $this->branch($zone);
        $lossBranch = $this->branch($zone);
        $otherZoneBranch = $this->branch($this->zone('Coast'));
        foreach ([$profitBranch, $zeroBranch, $lossBranch, $otherZoneBranch] as $branch) {
            $this->staff($branch, 200000);
        }
        $manager = $this->staff($profitBranch, 900000, 'zone_manager', ['zone_id' => $zone->id]);
        $period = $this->period(AccountingPeriod::STATUS_CLOSED);
        $this->branchResult($period, $profitBranch, 612244.90);
        $this->branchResult($period, $zeroBranch, 0);
        $this->branchResult($period, $lossBranch, -30000);
        $this->branchResult($period, $otherZoneBranch, 1000000);

        $this->engine()->calculate($this->admin->company_id, $this->month);
        $report = $this->engine()->report($this->admin->company_id, $this->month);

        // Pool 10% × (612,244.90 − 2% hold = 600,000.00) = 60,000; zone pool = 60,000 + 0 + 0; override 5% = 3,000.
        $line = collect($report['zone_managers'])->firstWhere('employee_id', $manager->id);
        $this->assertEquals(60000, $line['zone_pool']);
        $this->assertEquals(3000, $line['amount']);
        $this->assertEquals(3000, CommissionAllocation::where('kind', CommissionAllocation::KIND_ZONE_MANAGER)->value('amount'));
        $this->assertEquals([60000.0, 0.0, 0.0], array_column($line['contributions'], 'pool_amount'));
        $profitRow = $this->reportBranch($report, $profitBranch);
        $this->assertEquals(57000, $profitRow['staff_pool_amount'], 'D2: the override is carved out of the branch pool');
        $this->assertEquals(60000, array_sum(array_column($profitRow['staff'], 'amount')) + $line['amount']);
    }

    public function test_totals_and_summary_counts(): void
    {
        $eligible = $this->branch();
        $zero = $this->branch();
        $loss = $this->branch();
        $staff = $this->staff($eligible, 100000);
        $this->staff($zero, 100000);
        $this->staff($loss, 100000);
        $period = $this->period(AccountingPeriod::STATUS_CLOSED);
        $this->branchResult($period, $eligible, 510204.08);
        $this->branchResult($period, $zero, 0);
        $this->branchResult($period, $loss, -1);

        $preview = $this->engine()->report($this->admin->company_id, $this->month);
        $this->assertFalse($preview['calculated']);
        $this->assertTrue($preview['can_calculate']);
        $this->assertSame(['branches_eligible' => 1, 'branches_in_loss' => 1, 'branches_no_profit' => 1, 'total_pools' => 50000.0], $preview['summary']);
        $this->assertEquals(47500, $preview['total_commission'], 'C4: no zone manager → staff 95 % of the pool');
        $this->assertEquals(2500, $preview['total_returned_no_zone_manager']);

        $this->postJson('/api/v1/hrm/commission/calculate', ['period' => $this->month->format('Y-m')])->assertOk()->assertJsonPath('data.total', 47500);
        $report = $this->getJson("/api/v1/hrm/commission?period={$this->month->format('Y-m')}")->assertOk()->json('data');

        $this->assertTrue($report['calculated']);
        $this->assertSame(CommissionEngine::RULE_PROFIT_ALLOCATION, $report['rule'], 'zero-amount rows of the zero/loss branches do not make the month look legacy');
        $this->assertEquals(47500, $report['total_commission']);
        $this->assertEquals([2500, 2500, 0], [$report['total_returned_to_profit'], $report['total_returned_no_zone_manager'], $report['total_returned_no_staff']]);
        $this->assertEquals(['branches_eligible' => 1, 'branches_in_loss' => 1, 'branches_no_profit' => 1, 'total_pools' => 50000], $report['summary']);
        $this->assertEquals(47500, CommissionAllocation::where('employee_id', $staff->id)->value('amount'));
        $this->assertEquals(47500, CommissionAllocation::sum('amount'));
    }

    public function test_an_approved_payroll_still_locks_recalculation(): void
    {
        $branch = $this->branch();
        $this->staff($branch, 100000);
        $period = $this->period(AccountingPeriod::STATUS_CLOSED);
        $this->branchResult($period, $branch, 102040.82);
        $this->engine()->calculate($this->admin->company_id, $this->month);

        $run = PayrollRun::create(['company_id' => $this->admin->company_id, 'period' => $this->month->toDateString(), 'status' => PayrollRun::STATUS_DRAFT]);
        CommissionAllocation::query()->update(['payroll_run_id' => $run->id]);
        $this->assertTrue($this->engine()->report($this->admin->company_id, $this->month)['can_calculate']);

        $run->update(['status' => 'approved']);
        $report = $this->engine()->report($this->admin->company_id, $this->month);
        $this->assertTrue($report['locked']);
        $this->assertFalse($report['can_calculate']);
        $this->assertSame(CommissionEngine::LOCKED_MESSAGE, $report['calculate_blocked_reason']);

        $this->postJson('/api/v1/hrm/commission/calculate', ['period' => $this->month->format('Y-m')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period' => CommissionEngine::LOCKED_MESSAGE]);
        $this->assertEquals(9500, CommissionAllocation::sum('amount'));
    }

    public function test_june_like_data_produces_identical_commission_before_and_after_recalculation(): void
    {
        HrmSetting::forCompany($this->admin->company_id)->update(['commission_pool_percent' => 10, 'zone_override_percent' => 5]);
        $zones = [1 => $this->zone('Z1'), 2 => $this->zone('Z2'), 3 => $this->zone('Z3')];
        $period = $this->period(AccountingPeriod::STATUS_CLOSED);

        // [zone, gross profit, loss brought forward, net profit, HQ hold, distributable, eligible, [[salary, expected commission], …]]
        // User decision D2 / C4: 5% of every branch pool is carved out. Zone 1 has a zone manager, who receives it; zones 2 and 3
        // have none, so that 5% returns to profit. Staff always share the remaining 95% (the stored June 2026 rows keep the old
        // override-on-top figures).
        $fixture = [
            [3, '0.00', '0.00', '0.00', '0.00', '0.00', false, [[300000, '0.00'], [250000, '0.00'], [300000, '0.00'], [400000, '0.00'], [250000, '0.00'], [300000, '0.00'], [400000, '0.00']]],
            [3, '665000.00', '0.00', '665000.00', '13300.00', '651700.00', true, [[400000, '13386.27'], [300000, '10039.70'], [400000, '13386.27'], [250000, '8366.42'], [500000, '16732.84']]],
            [3, '291000.00', '25000.00', '266000.00', '5320.00', '260680.00', true, [[250000, '4269.76'], [300000, '5123.71'], [400000, '6831.61'], [500000, '8539.52']]],
            [2, '200000.00', '0.00', '200000.00', '4000.00', '196000.00', true, [[250000, '2738.24'], [300000, '3285.88'], [400000, '4381.18'], [250000, '2738.24'], [500000, '5476.46']]],
            [2, '0.00', '0.00', '0.00', '0.00', '0.00', false, [[250000, '0.00'], [300000, '0.00'], [400000, '0.00']]],
            [2, '0.00', '0.00', '0.00', '0.00', '0.00', false, [[250000, '0.00'], [300000, '0.00'], [400000, '0.00']]],
            [1, '1683000.00', '170000.00', '1513000.00', '30260.00', '1482740.00', true, [[800000, '50760.47'], [520000, '32994.30'], [480000, '30456.28'], [420000, '26649.25']]],
            [1, '851000.00', '370000.00', '481000.00', '9620.00', '471380.00', true, [[750000, '20355.05'], [500000, '13570.03'], [400000, '10856.02']]],
            [1, '1603000.00', '170000.00', '1433000.00', '28660.00', '1404340.00', true, [[850000, '49956.15'], [520000, '30561.41'], [480000, '28210.53'], [420000, '24684.21']]],
            [1, '1571000.00', '158000.00', '1413000.00', '28260.00', '1384740.00', true, [[720000, '45978.75'], [480000, '30652.50'], [400000, '25543.75'], [460000, '29375.30']]],
            [1, '851000.00', '170000.00', '681000.00', '13620.00', '667380.00', true, [[720000, '28891.64'], [480000, '19261.09'], [380000, '15248.37']]],
        ];

        $expected = [];
        $zoneBranch = null;
        foreach ($fixture as [$zone, $gross, $lossBroughtForward, $net, $hold, $distributable, $eligible, $staff]) {
            $branch = $this->branch($zones[$zone]);
            BranchPeriodResult::create([
                'accounting_period_id' => $period->id, 'branch_id' => $branch->id, 'gross_profit' => $gross, 'loss_brought_forward' => $lossBroughtForward,
                'net_profit' => $net, 'loss_carried_forward' => 0, 'hq_hold_percent' => 2, 'hq_hold_amount' => $hold, 'distributable_profit' => $distributable, 'commission_eligible' => $eligible,
            ]);
            foreach ($staff as [$salary, $amount]) {
                $expected[$this->staff($branch, $salary)->id] = $amount;
            }
            if ($zone === 1 && $zoneBranch === null) {
                $zoneBranch = $branch;
            }
        }
        $manager = $this->staff($zoneBranch, 1400000, 'zone_manager', ['zone_id' => $zones[1]->id]);

        $snapshot = fn (): array => CommissionAllocation::orderBy('kind')->orderBy('employee_id')->get(['branch_id', 'zone_id', 'employee_id', 'kind', 'distributable_profit', 'pool_percent', 'pool_amount', 'base_salary', 'total_salary', 'share_percent', 'amount'])->toArray();

        $this->engine()->calculate($this->admin->company_id, $this->month);
        $first = $snapshot();
        $report = $this->engine()->report($this->admin->company_id, $this->month);

        $this->engine()->calculate($this->admin->company_id, $this->month);
        $this->assertSame($first, $snapshot());

        $actual = CommissionAllocation::where('kind', CommissionAllocation::KIND_BRANCH_STAFF)->pluck('amount', 'employee_id')->all();
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
        $this->assertSame('27052.90', CommissionAllocation::where('employee_id', $manager->id)->value('amount'));
        $this->assertSame('541058.00', CommissionAllocation::where('employee_id', $manager->id)->value('pool_amount'));
        $this->assertEquals(646354.10, $report['total_commission'], 'C4: 10% of distributable profit less the 5% of zones 2 and 3 (no zone manager)');
        $this->assertEquals(5541.90, $report['total_returned_no_zone_manager']);
        $this->assertEquals(651896, $report['summary']['total_pools']);
        $this->assertEquals(6518960, array_sum(array_column($report['branches'], 'distributable_profit')));
        $this->assertSame(['branches_eligible' => 8, 'branches_in_loss' => 0, 'branches_no_profit' => 3, 'total_pools' => 651896.0], $report['summary']);
        foreach (collect($report['branches'])->where('eligible', false) as $branch) {
            $this->assertSame('No distributable profit', $branch['blocked_reason']);
        }
    }
}
