<?php

namespace Tests\Feature\Api\Hrm;

use App\Enums\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\BranchPeriodResult;
use App\Models\Employee;
use App\Models\HrmSetting;
use App\Models\PayrollRun;
use App\Models\SalaryPayment;
use App\Models\StaffAllowance;
use App\Models\StaffDeduction;
use App\Models\StaffLoan;
use App\Models\StaffLoanCategory;
use App\Models\StaffSalaryAdvance;
use App\Models\StaffSalaryAdvanceCategory;
use App\Models\Zone;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

class PayrollApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesSecondApprover;

    private Employee $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
        HrmSetting::forCompany($this->admin->company_id)->update(['commission_pool_percent' => 10, 'zone_override_percent' => 5, 'staff_fund_percent' => 10]);
    }

    private function staff(int $salary, string $type = 'branch', array $attributes = []): Employee
    {
        $employee = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id] + $attributes);
        $employee->salaryInfo()->create(['salary' => $salary, 'salary_type' => $type, 'commission_eligible' => $type !== 'hq', 'payment_method' => 'bank', 'account_name' => 'NMB', 'account_number' => '67676', 'fee' => 0]);

        return $employee;
    }

    private function balance(Account $account, ?int $branch = null, ?int $employee = null): float
    {
        return app(Ledger::class)->balance($this->admin->company_id, $account, $branch, employee: $employee);
    }

    private function closePeriod(array $results): void
    {
        $period = AccountingPeriod::create(['company_id' => $this->admin->company_id, 'period_start' => now()->subMonthNoOverflow()->startOfMonth(), 'period_end' => now()->subMonthNoOverflow()->endOfMonth(), 'status' => 'closed']);
        foreach ($results as $branchId => $distributable) {
            BranchPeriodResult::create(['accounting_period_id' => $period->id, 'branch_id' => $branchId, 'net_profit' => max(0, $distributable), 'distributable_profit' => $distributable, 'commission_eligible' => $distributable > 0]);
        }
    }

    public function test_commission_shows_period_not_closed_then_distributes_pool_by_salary_share(): void
    {
        $zone = Zone::create(['company_id' => $this->admin->company_id, 'name' => 'Lake']);
        $this->admin->branch->update(['zone_id' => $zone->id]);
        $lossBranch = Branch::factory()->create(['company_id' => $this->admin->company_id, 'zone_id' => $zone->id]);
        $a = $this->staff(300000);
        $b = $this->staff(100000);
        $hq = $this->staff(500000, 'hq');
        $zoneManager = $this->staff(400000, 'zone_manager', ['zone_id' => $zone->id]);
        $period = now()->subMonthNoOverflow()->format('Y-m');

        $this->getJson("/api/v1/hrm/commission?period={$period}")->assertOk()->assertJsonPath('data.period_closed', false);
        $this->postJson('/api/v1/hrm/commission/calculate', ['period' => $period])->assertUnprocessable()->assertJsonValidationErrors('period');

        $this->closePeriod([$this->admin->branch_id => 1000000, $lossBranch->id => -50000]);

        $this->postJson('/api/v1/hrm/commission/calculate', ['period' => $period])->assertOk();

        $report = $this->getJson("/api/v1/hrm/commission?period={$period}")->assertOk()->json('data');
        $this->assertTrue($report['calculated']);
        $branch = collect($report['branches'])->firstWhere('branch_id', $this->admin->branch_id);
        $this->assertEquals(100000, $branch['pool_amount']);
        // D2: the zone manager's 5% (5,000) is carved out of the 100,000 pool; staff share the remaining 95,000 by salary.
        $this->assertEquals(5000, $branch['zone_manager_amount']);
        $this->assertEquals(95000, $branch['staff_pool_amount']);
        $this->assertEquals(71250, collect($branch['staff'])->firstWhere('employee_id', $a->id)['amount']);
        $this->assertEquals(23750, collect($branch['staff'])->firstWhere('employee_id', $b->id)['amount']);
        $this->assertEquals(100000, $report['total_commission']);
        $this->assertNull(collect($branch['staff'])->firstWhere('employee_id', $hq->id));
        $blocked = collect($report['branches'])->firstWhere('branch_id', $lossBranch->id);
        $this->assertFalse($blocked['eligible']);
        $this->assertEquals(0, $blocked['pool_amount']);
        $this->assertEquals(5000, collect($report['zone_managers'])->firstWhere('employee_id', $zoneManager->id)['amount']);
    }

    public function test_hr_generates_and_approves_finance_pays_with_automatic_deductions(): void
    {
        $companyId = $this->admin->company_id;
        $branchId = $this->admin->branch_id;
        $staff = $this->staff(400000);
        $hq = $this->staff(200000, 'hq');
        $this->closePeriod([$branchId => 400000]);

        StaffAllowance::create(['company_id' => $companyId, 'branch_id' => $branchId, 'employee_id' => $staff->id, 'amount' => 100000, 'status' => 'active']);
        $deduction = StaffDeduction::create(['company_id' => $companyId, 'branch_id' => $branchId, 'employee_id' => $staff->id, 'amount' => 100000, 'instalments' => 2, 'instalment_amount' => 50000]);
        $advanceCategory = StaffSalaryAdvanceCategory::create(['company_id' => $companyId, 'name' => 'ADV', 'amount_from' => 0, 'amount_to' => 100000]);
        $advance = StaffSalaryAdvance::create(['company_id' => $companyId, 'branch_id' => $branchId, 'employee_id' => $staff->id, 'staff_salary_advance_category_id' => $advanceCategory->id, 'amount' => 20000, 'status' => 'disbursed', 'source_account' => Account::Company->value]);
        $loanCategory = StaffLoanCategory::create(['company_id' => $companyId, 'name' => 'L', 'amount_from' => 1000, 'amount_to' => 10000, 'interest_rate' => 20, 'duration' => 'monthly', 'repayment_from' => 1, 'repayment_to' => 3]);
        $loan = StaffLoan::create(['company_id' => $companyId, 'branch_id' => $branchId, 'employee_id' => $staff->id, 'staff_loan_category_id' => $loanCategory->id, 'amount_applied' => 10000, 'amount_approved' => 10000, 'duration' => 'monthly', 'sessions' => 3, 'total_payable' => 12000, 'restoration' => 4000, 'reason' => 'x', 'status' => 'disbursed']);
        $period = now()->subMonthNoOverflow()->format('Y-m');

        // Spec §21 / §22: commission is not a payroll column (it is paid through the commission payment flow), even though the
        // month is closed. Gross 400,000 + 100,000 allowance = 500,000. Deductions: fund 40,000 + advance 20,000 + deduction
        // 50,000 + loan 4,000 = 114,000 → take home 386,000.
        $this->getJson("/api/v1/hrm/payroll?period={$period}")->assertOk()->assertJsonPath('data.run', null);

        $finance = Employee::factory()->create(['company_id' => $companyId, 'branch_id' => $branchId, 'role_id' => $this->admin->company->roles()->where('key', 'finance')->value('id')]);
        $hr = Employee::factory()->create(['company_id' => $companyId, 'branch_id' => $branchId, 'role_id' => $this->admin->company->roles()->where('key', 'hr')->value('id')]);

        $this->actingAs($finance)->postJson('/api/v1/hrm/payroll/generate', ['period' => $period])->assertForbidden();
        $this->actingAs($hr)->postJson('/api/v1/hrm/payroll/generate', ['period' => $period])->assertOk();

        $run = PayrollRun::firstOrFail();
        $rows = collect($this->getJson("/api/v1/hrm/payroll?period={$period}")->json('data.rows'));
        $row = $rows->firstWhere('employee_id', $staff->id);
        $this->assertEquals(0, $row['commission']);
        $this->assertEquals(500000, $row['gross']);
        $this->assertEquals(386000, $row['take_home']);
        $this->assertEquals(0, $rows->firstWhere('employee_id', $hq->id)['commission']);
        $this->assertEquals(180000, $rows->firstWhere('employee_id', $hq->id)['take_home']);

        $this->actingAs($hr)->postJson("/api/v1/hrm/payroll/{$run->id}/pay", ['ac_id' => 'interest'])->assertForbidden();
        $this->actingAs($finance)->postJson("/api/v1/hrm/payroll/{$run->id}/pay", ['ac_id' => 'interest'])->assertUnprocessable();

        // §31 ruling: HR prepares and reviews, Finance gives the final approval — no HR user can approve, not even another one.
        $this->actingAs($hr)->postJson("/api/v1/hrm/payroll/{$run->id}/approve")->assertForbidden();
        $this->actingAs($this->secondApprover($hr, 'hr'))->postJson("/api/v1/hrm/payroll/{$run->id}/approve")->assertForbidden();
        $this->actingAs($finance)->postJson("/api/v1/hrm/payroll/{$run->id}/approve")->assertOk();
        $this->assertEquals(500000, $this->balance(Account::StaffPayable, employee: $staff->id));
        // Payroll neither calculates nor recognises commission: no commission expense, payable or profit allocation.
        $this->assertEquals(0, $this->balance(Account::CommissionExpense, $branchId));
        $this->assertEquals(0, $this->balance(Account::CommissionPayable, $branchId, $staff->id));
        $this->assertEquals(0, $this->balance(Account::RetainedProfit, $branchId));
        // Spec §26 (ruling 2026-09-17): salary expense is the basic salary only — no extra company 20 % on top.
        $this->assertEquals(200000, $this->balance(Account::SalaryExpense));
        $this->actingAs($hr)->postJson('/api/v1/hrm/payroll/generate', ['period' => $period])->assertUnprocessable();
        $this->actingAs($hr)->putJson("/api/v1/hrm/staff/{$staff->id}/salary", ['salary' => 500000, 'account_name' => 'NMB', 'account_number' => '1', 'fee_salary' => 0, 'salary_type' => 'branch', 'commission_eligible' => true, 'payment_method' => 'bank'])->assertUnprocessable();

        // Operation Income may never go negative: with 449,999 in the branch INTEREST A/C (1 short) nothing is paid at all.
        $this->fundPayroll($this->admin, 449999, 180000);
        $this->actingAs($finance)->postJson("/api/v1/hrm/payroll/{$run->id}/pay", ['ac_id' => 'interest'])->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'Insufficient balance in INTEREST A/C ('.Branch::find($branchId)->name.'): available 449,999.00, payroll needs 450,000.00.')
            ->assertJsonPath('errors.status.1', 'Insufficient balance in Operation Income: available 449,999.00, payroll needs 450,000.00.');
        $this->assertSame('approved', $run->fresh()->status);
        $this->assertSame(0, SalaryPayment::count());
        $this->assertEquals(449999, $this->balance(Account::Interest, $branchId));
        $this->assertEquals(500000, $this->balance(Account::StaffPayable, employee: $staff->id));

        $this->fundPayroll($this->admin, 1, 0);
        $this->actingAs($finance)->postJson("/api/v1/hrm/payroll/{$run->id}/pay", ['ac_id' => 'interest'])->assertOk()->assertJsonPath('message', 'Salary Paid successfully');

        $this->assertSame('paid', $run->fresh()->status);
        $payment = SalaryPayment::where('employee_id', $staff->id)->firstOrFail();
        $this->assertEquals(386000, $payment->take_home);
        $this->assertEquals(0, $this->balance(Account::StaffPayable, employee: $staff->id));
        $this->assertEquals(40000, $this->balance(Account::StaffFund, employee: $staff->id));
        $this->assertEquals(20000, $this->balance(Account::StaffFund, employee: $hq->id));
        // Fund cash: 40,000 + 20,000 contributions + 4,000 loan restoration.
        $this->assertEquals(64000, $this->balance(Account::StaffFundCash));
        // Interest pays branch staff: take home 386,000 + fund 40,000 + loan 4,000 + advance back to HQ 20,000.
        $this->assertEquals(0, $this->balance(Account::Interest, $branchId));
        // Company account pays HQ staff 180,000 + 20,000 fund, receives the 20,000 advance recovery.
        $this->assertEquals(0, $this->balance(Account::Company));
        $this->assertEquals(-20000, $this->balance(Account::StaffAdvanceReceivable, employee: $staff->id));
        $this->assertSame('completed', $advance->fresh()->status);
        $this->assertSame('repaying', $loan->fresh()->status);
        $this->assertEquals(50000, $deduction->fresh()->paid_amount);
        $this->assertEquals(4000, $loan->payments()->sum('amount'));

        $this->getJson("/api/v1/hrm/salary-payments/{$payment->id}")->assertOk()->assertJsonPath('data.commission', 0)->assertJsonPath('data.staff_fund', 40000);
        $this->getJson('/api/v1/hrm/salary-payments?from='.now()->toDateString().'&to='.now()->toDateString())->assertOk()->assertJsonCount(2, 'data');

        $fund = $this->actingAs($finance)->getJson('/api/v1/hrm/staff-fund')->assertOk()->json('data');
        $this->assertEquals(64000, $fund['balance']);
        $this->assertCount(2, $fund['members']);

        // Spec §27 benefit claim: HR prepares (up to the recorded benefit), Finance approves and pays from the STAFF FUND A/C.
        $this->actingAs($finance)->postJson('/api/v1/hrm/staff-fund/claims', ['empl_id' => $hq->id, 'amount' => 20000, 'reason' => 'exit'])->assertForbidden();
        $this->actingAs($hr)->postJson('/api/v1/hrm/staff-fund/claims', ['empl_id' => $hq->id, 'amount' => 25000, 'reason' => 'exit'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $claimId = $this->actingAs($hr)->postJson('/api/v1/hrm/staff-fund/claims', ['empl_id' => $hq->id, 'amount' => 20000, 'reason' => 'exit'])->assertCreated()->json('data.id');
        $this->assertEquals(64000, $this->balance(Account::StaffFundCash));
        $this->actingAs($finance)->postJson("/api/v1/hrm/staff-fund/claims/{$claimId}/approve")->assertOk();
        $this->actingAs($finance)->postJson("/api/v1/hrm/staff-fund/claims/{$claimId}/pay")->assertOk();
        $this->assertEquals(44000, $this->balance(Account::StaffFundCash));
        $this->assertEquals(0, $this->balance(Account::StaffFund, employee: $hq->id));
    }
}
