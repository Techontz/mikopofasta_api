<?php

namespace Tests\Feature\Api\Hrm;

use App\Models\Employee;
use App\Models\Leave;
use App\Models\StaffAllowance;
use App\Models\StaffFundWithdrawal;
use App\Models\StaffLoan;
use App\Models\StaffLoanCategory;
use App\Models\StaffSalaryAdvance;
use App\Models\StaffSalaryAdvanceCategory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HR Department dashboard (GET /dashboard/hr): staff counts, pending staff requests, the month's payroll and the staff fund,
 * all from the HRM records.
 */
class HrDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    private Employee $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-08-15 09:00:00'));
        $this->admin = $this->signInAdmin();
    }

    public function test_hr_sees_staff_requests_payroll_and_staff_fund(): void
    {
        $companyId = $this->admin->company_id;
        $branchId = $this->admin->branch_id;
        $hr = $this->employeeWithRole('hr');
        $asha = $this->employeeWithRole('loan_officer', ['first_name' => 'Asha', 'middle_name' => null, 'last_name' => 'Juma']);
        $asha->salaryInfo()->create(['salary' => 400000, 'salary_type' => 'branch', 'payment_method' => 'bank', 'account_name' => 'ASHA JUMA', 'bank_name' => 'CRDB', 'account_number' => '0152000111', 'fee' => 0]);
        $this->employeeWithRole('loan_officer')->forceFill(['created_at' => '2026-07-10 08:00:00'])->save();
        $this->employeeWithRole('teller', ['status' => 'blocked']);
        $this->employeeWithRole('teller', ['status' => 'rejected']);
        $this->employeeWithRole('shareholder', ['account_type' => Employee::ACCOUNT_SHAREHOLDER]);
        $staffBefore = Employee::staff()->where('status', '!=', 'rejected')->count();

        $loanCategory = StaffLoanCategory::create(['company_id' => $companyId, 'name' => 'L', 'amount_from' => 1000, 'amount_to' => 100000, 'interest_rate' => 20, 'duration' => 'monthly', 'repayment_from' => 1, 'repayment_to' => 3]);
        StaffLoan::create(['company_id' => $companyId, 'branch_id' => $branchId, 'employee_id' => $asha->id, 'staff_loan_category_id' => $loanCategory->id, 'amount_applied' => 50000, 'duration' => 'monthly', 'sessions' => 2, 'total_payable' => 60000, 'restoration' => 30000, 'reason' => 'School fees', 'status' => 'submitted']);
        $advanceCategory = StaffSalaryAdvanceCategory::create(['company_id' => $companyId, 'name' => 'ADV', 'amount_from' => 0, 'amount_to' => 100000]);
        StaffSalaryAdvance::create(['company_id' => $companyId, 'branch_id' => $branchId, 'employee_id' => $asha->id, 'staff_salary_advance_category_id' => $advanceCategory->id, 'amount' => 30000, 'status' => 'hr_approved']);
        StaffAllowance::create(['company_id' => $companyId, 'branch_id' => $branchId, 'employee_id' => $asha->id, 'amount' => 25000, 'reason' => 'transport', 'payroll_period' => '2026-08-01', 'status' => StaffAllowance::STATUS_PENDING, 'created_by' => $hr->id]);
        StaffFundWithdrawal::create(['company_id' => $companyId, 'employee_id' => $asha->id, 'amount' => 15000, 'reason' => 'Medical', 'status' => StaffFundWithdrawal::STATUS_PREPARED, 'prepared_by' => $hr->id, 'prepared_at' => now()]);
        StaffFundWithdrawal::create(['company_id' => $companyId, 'employee_id' => $asha->id, 'amount' => 10000, 'reason' => 'Funeral', 'status' => StaffFundWithdrawal::STATUS_PAID, 'prepared_by' => $hr->id, 'prepared_at' => now(), 'paid_at' => now()]);
        Leave::create(['company_id' => $companyId, 'employee_id' => $asha->id, 'start_date' => '2026-08-20', 'end_date' => '2026-08-25', 'remarks' => 'Annual leave', 'status' => 'pending']);

        $data = $this->actingAs($hr)->getJson('/api/v1/dashboard/hr?month=2026-08')->assertOk()->json('data');

        $cards = $data['cards'];
        $this->assertSame($staffBefore, $cards['total_staff'], 'Rejected registrations and shareholder logins are not staff.');
        $this->assertSame(1, Employee::staff()->where('status', '!=', 'rejected')->where('created_at', '<=', '2026-07-31 23:59:59')->count());
        $this->assertEquals(($staffBefore - 1) * 100, $cards['total_staff_change'], 'One staff member was registered by the end of July.');
        $this->assertSame($staffBefore - 1, $cards['active_staff'], 'The blocked staff member is not active.');
        $this->assertSame($staffBefore - 1, $cards['new_staff']);
        $this->assertSame(5, $cards['pending_requests']);
        $this->assertSame(2, $cards['pending_staff_loans']);
        $this->assertEquals(80000, $cards['pending_staff_loans_amount']);
        $this->assertSame(1, $cards['pending_allowances']);
        $this->assertSame(1, $cards['pending_claims']);

        // No payroll generated yet: the live preview — 400,000 less the 20% staff fund.
        $this->assertSame('Not generated', $cards['payroll_status']);
        $this->assertSame(1, $cards['payroll_employees']);
        $this->assertEquals(320000, $cards['payroll_net']);
        $summary = collect($data['payroll_summary'])->keyBy('label');
        $this->assertEquals(400000, $summary['Basic Salary']['amount']);
        $this->assertEquals(80000, $summary['Staff Fund Contribution (Deduction)']['amount']);
        $this->assertEquals(320000, $summary['Net Salary']['amount']);
        $this->assertEquals(320000, collect($data['payroll_trend'])->firstWhere('month', '2026-08')['net']);

        $this->assertSame(2, collect($data['departments'])->firstWhere('label', 'Loan Officer')['count']);
        $types = collect($data['requests_by_type'])->keyBy('key');
        $this->assertSame([1, 1, 1, 2, 1, 0], $types->pluck('count')->all());

        $fund = collect($data['staff_fund'])->firstWhere('month', '2026-08');
        $this->assertEquals([10000, 15000], [$fund['benefits_paid'], $fund['pending_claims']]);

        $pending = collect($data['pending'])->keyBy('type');
        $this->assertSame(['Asha Juma', 'Pending', 50000.0], [$pending['staff_loan']['staff'], $pending['staff_loan']['status'], (float) $pending['staff_loan']['amount']]);
        $this->assertSame('Under Review', $pending['salary_advance']['status']);
        $this->assertSame('Transport', $pending['allowance']['detail']);
    }

    public function test_only_staff_who_manage_hr_see_it(): void
    {
        $this->actingAs($this->employeeWithRole('finance'))->getJson('/api/v1/dashboard/hr')->assertForbidden();
        $this->actingAs($this->employeeWithRole('hr'))->getJson('/api/v1/dashboard/hr?month=2026-13')->assertJsonValidationErrors('month');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function employeeWithRole(string $role, array $attributes = []): Employee
    {
        return Employee::factory()->create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->admin->branch_id,
            'role_id' => $this->admin->company->roles()->where('key', $role)->value('id'),
        ] + $attributes);
    }
}
