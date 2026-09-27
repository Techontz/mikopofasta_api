<?php

namespace Tests\Feature\Api\Hrm;

use App\Enums\Account;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\SalaryPayment;
use App\Services\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Specification §26 as ruled by the product owner (2026-09-17): salary expense is the full basic salary; the 20 % withheld
 * from it goes into the STAFF FUND A/C as real cash, and there is no separate company contribution on top.
 * Basic 1,000,000 → expense 1,000,000, staff receives 800,000, fund receives 200,000.
 */
class StaffFundContributionTest extends TestCase
{
    use RefreshDatabase;

    private Employee $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-25 09:00:00'));
        $this->admin = $this->signInAdmin();
    }

    private function employeeWithRole(string $role): Employee
    {
        return Employee::factory()->create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->admin->branch_id,
            'role_id' => $this->admin->company->roles()->where('key', $role)->value('id'),
        ]);
    }

    private function balance(Account $account, ?int $employee = null): float
    {
        return app(Ledger::class)->balance($this->admin->company_id, $account, employee: $employee, allBranches: $employee === null);
    }

    public function test_salary_expense_is_the_basic_salary_and_the_fund_receives_the_twenty_percent_in_cash(): void
    {
        $hr = $this->employeeWithRole('hr');
        $finance = $this->employeeWithRole('finance');
        $this->actingAs($hr)->getJson('/api/v1/hrm/settings')->assertOk()
            ->assertJsonPath('data.staff_fund_percent', 20)
            ->assertJsonMissingPath('data.company_fund_percent');

        $staff = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id]);
        $staff->salaryInfo()->create(['salary' => 1000000, 'salary_type' => 'hq', 'commission_eligible' => false, 'payment_method' => 'bank', 'account_name' => 'NMB', 'account_number' => '1', 'fee' => 0]);

        $this->actingAs($hr)->postJson('/api/v1/hrm/payroll/generate', ['period' => '2026-07'])->assertOk();
        $run = collect($this->actingAs($hr)->getJson('/api/v1/hrm/payroll?period=2026-07')->json('data.rows'))->firstWhere('employee_id', $staff->id);
        $this->assertEquals([1000000, 200000, 800000], [$run['base_salary'], $run['staff_fund'], $run['take_home']]);
        $this->assertArrayNotHasKey('company_fund', $run);

        $runId = PayrollRun::sole()->id;
        $this->actingAs($finance)->postJson("/api/v1/hrm/payroll/{$runId}/approve")->assertOk();
        $this->assertEquals(1000000, $this->balance(Account::SalaryExpense), 'the basic salary only — no extra 20 % expense');
        $this->assertEquals(0, $this->balance(Account::StaffFundCash), 'no fund cash before the salary is paid');

        $this->fundPayroll($this->admin);
        $this->actingAs($finance)->postJson("/api/v1/hrm/payroll/{$runId}/pay", ['ac_id' => 'interest'])->assertOk();

        $this->assertEquals(1000000, $this->balance(Account::SalaryExpense));
        $this->assertEquals(200000, $this->balance(Account::StaffFundCash), 'the fund holds the 200,000 actually withheld');
        $this->assertEquals(200000, $this->balance(Account::StaffFund, $staff->id));

        $fund = $this->actingAs($finance)->getJson('/api/v1/hrm/staff-fund')->assertOk()->json('data');
        $this->assertEquals([200000, 200000], [$fund['balance'], $fund['total_benefit_record']]);
        $this->assertArrayNotHasKey('company_contributions_owed', $fund);

        $this->actingAs($hr)->getJson('/api/v1/hrm/salary-payments/'.SalaryPayment::sole()->id)->assertOk()
            ->assertJsonPath('data.staff_fund', 200000)
            ->assertJsonPath('data.benefit_record', 200000)
            ->assertJsonPath('data.take_home', 800000)
            ->assertJsonMissingPath('data.company_fund');
        $this->actingAs($hr)->getJson("/api/v1/hrm/staff/{$staff->id}")->assertOk()
            ->assertJsonPath('data.staff_fund_benefit.total_benefit_record', 200000);
    }
}
