<?php

namespace Tests\Feature\Api\Hrm;

use App\Enums\Account;
use App\Models\AccountingPeriod;
use App\Models\BranchPeriodResult;
use App\Models\Employee;
use App\Models\HrmSetting;
use App\Models\NegligenceDeduction;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\SalaryPayment;
use App\Models\StaffAllowance;
use App\Models\StaffDeduction;
use App\Models\StaffFundWithdrawal;
use App\Models\StaffLoan;
use App\Models\StaffLoanCategory;
use App\Models\StaffSalaryAdvance;
use App\Models\StaffSalaryAdvanceCategory;
use App\Services\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec §60 Employee Portal: a staff member sees only their own payslips, staff fund records, loans and advances, commission,
 * allowances and negligence deductions — never another employee's records and never a central balance.
 */
class EmployeePortalApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINTS = ['overview', 'payslips', 'staff-fund', 'commission', 'allowances', 'negligence', 'repayments'];

    /** Opening STAFF FUND A/C cash no employee owns: must never appear in a portal payload. */
    private const FUND_OPENING_CASH = 7_777_777;

    private Employee $admin;

    private Employee $hr;

    private Employee $me;

    private Employee $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-08-10 09:00:00'));
        $this->admin = $this->signInAdmin();
        HrmSetting::forCompany($this->admin->company_id)->update(['commission_pool_percent' => 10, 'zone_override_percent' => 5, 'staff_fund_percent' => 20]);
        $this->hr = $this->employeeWithRole('hr');
        $this->me = $this->staff(300000);
        $this->other = $this->staff(100000);
    }

    private function employeeWithRole(string $role): Employee
    {
        return Employee::factory()->create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->admin->branch_id,
            'role_id' => $this->admin->company->roles()->where('key', $role)->value('id'),
        ]);
    }

    private function staff(int $salary): Employee
    {
        $employee = $this->employeeWithRole('loan_officer');
        $employee->salaryInfo()->create(['salary' => $salary, 'salary_type' => 'branch', 'commission_eligible' => true, 'payment_method' => 'bank', 'account_name' => 'NMB', 'account_number' => '1', 'fee' => 0]);

        return $employee;
    }

    /**
     * July payroll paid, August payroll approved but unpaid, fund contributions, claims, allowances, deductions, negligence, a
     * staff loan and a salary advance — for both employees, with distinct figures.
     */
    private function seedRecords(): void
    {
        $companyId = $this->admin->company_id;
        $branchId = $this->admin->branch_id;
        $ledger = app(Ledger::class);

        $july = PayrollRun::create(['company_id' => $companyId, 'period' => '2026-07-01', 'status' => PayrollRun::STATUS_PAID, 'total_gross' => 999_111, 'total_net' => 888_111]);
        $august = PayrollRun::create(['company_id' => $companyId, 'period' => '2026-08-01', 'status' => PayrollRun::STATUS_APPROVED, 'total_gross' => 999_222, 'total_net' => 888_222]);

        foreach ([[$this->me, 300000, 41000, 6000, 12000, 201000], [$this->other, 100000, 43000, 7000, 13000, 57000]] as [$employee, $salary, $allowance, $advance, $loan, $net]) {
            $payment = SalaryPayment::create([
                'company_id' => $companyId, 'employee_id' => $employee->id, 'payroll_run_id' => $july->id, 'branch_id' => $branchId, 'salary_type' => 'branch',
                'salary' => $salary, 'allowance' => $allowance, 'staff_fund' => $salary * 0.2, 'salary_advance' => $advance, 'loan_restoration' => $loan,
                'deduction' => 2000, 'take_home' => $net, 'paid_from_account' => 'INTEREST ACC', 'paid_on' => '2026-07-28',
            ]);
            PayrollItem::create(['payroll_run_id' => $july->id, 'employee_id' => $employee->id, 'branch_id' => $branchId, 'salary_type' => 'branch', 'base_salary' => $salary, 'take_home' => $net, 'salary_payment_id' => $payment->id]);
            PayrollItem::create(['payroll_run_id' => $august->id, 'employee_id' => $employee->id, 'branch_id' => $branchId, 'salary_type' => 'branch', 'base_salary' => $salary, 'staff_fund' => $salary * 0.2, 'take_home' => $net + 1]);

            $ledger->journal($companyId, 'Salary payment contribution', [
                ['account' => Account::StaffFundCash, 'debit' => $salary * 0.2],
                ['account' => Account::StaffFund, 'employee' => $employee->id, 'credit' => $salary * 0.2],
            ]);
        }

        $ledger->journal($companyId, 'Fund opening cash', [
            ['account' => Account::StaffFundCash, 'debit' => self::FUND_OPENING_CASH],
            ['account' => Account::Capital, 'credit' => self::FUND_OPENING_CASH],
        ]);

        StaffFundWithdrawal::create(['company_id' => $companyId, 'employee_id' => $this->me->id, 'amount' => 15000, 'reason' => 'Medical', 'status' => StaffFundWithdrawal::STATUS_FINANCE_REVIEW, 'prepared_by' => $this->hr->id, 'prepared_at' => now()]);
        StaffFundWithdrawal::create(['company_id' => $companyId, 'employee_id' => $this->other->id, 'amount' => 17000, 'reason' => 'Other staff claim', 'status' => StaffFundWithdrawal::STATUS_PREPARED, 'prepared_by' => $this->hr->id, 'prepared_at' => now()]);

        StaffAllowance::create(['company_id' => $companyId, 'branch_id' => $branchId, 'employee_id' => $this->me->id, 'amount' => 100000, 'reason' => 'overtime', 'payroll_period' => '2026-08-01', 'status' => StaffAllowance::STATUS_APPROVED, 'created_by' => $this->hr->id]);
        StaffAllowance::create(['company_id' => $companyId, 'branch_id' => $branchId, 'employee_id' => $this->me->id, 'amount' => 25000, 'reason' => 'transport', 'payroll_period' => '2026-09-01', 'status' => StaffAllowance::STATUS_PENDING, 'created_by' => $this->hr->id]);
        StaffAllowance::create(['company_id' => $companyId, 'branch_id' => $branchId, 'employee_id' => $this->other->id, 'amount' => 66000, 'reason' => 'leave', 'payroll_period' => '2026-08-01', 'status' => StaffAllowance::STATUS_APPROVED, 'created_by' => $this->hr->id]);
        StaffDeduction::create(['company_id' => $companyId, 'branch_id' => $branchId, 'employee_id' => $this->me->id, 'amount' => 8000, 'instalments' => 4, 'instalment_amount' => 2000, 'paid_amount' => 2000, 'description' => 'Uniform', 'status' => 'active']);
        StaffDeduction::create(['company_id' => $companyId, 'branch_id' => $branchId, 'employee_id' => $this->other->id, 'amount' => 9000, 'instalments' => 3, 'instalment_amount' => 3000, 'description' => 'Other staff deduction', 'status' => 'active']);

        $mine = NegligenceDeduction::create(['company_id' => $companyId, 'employee_id' => $this->me->id, 'branch_id' => $branchId, 'amount' => 30000, 'recovered_amount' => 0, 'reason' => 'Lost cash', 'status' => NegligenceDeduction::STATUS_APPROVED, 'created_by' => $this->hr->id, 'approved_at' => now()]);
        NegligenceDeduction::create(['company_id' => $companyId, 'employee_id' => $this->me->id, 'branch_id' => $branchId, 'amount' => 11000, 'reason' => 'Not yet approved', 'status' => NegligenceDeduction::STATUS_PENDING, 'created_by' => $this->hr->id]);
        NegligenceDeduction::create(['company_id' => $companyId, 'employee_id' => $this->other->id, 'branch_id' => $branchId, 'amount' => 44000, 'reason' => 'Other staff negligence', 'status' => NegligenceDeduction::STATUS_APPROVED, 'created_by' => $this->hr->id, 'approved_at' => now()]);
        $this->assertNotNull($mine->id);

        $loanCategory = StaffLoanCategory::create(['company_id' => $companyId, 'name' => 'Emergency', 'amount_from' => 1000, 'amount_to' => 100000, 'interest_rate' => 20, 'duration' => 'monthly', 'repayment_from' => 1, 'repayment_to' => 3]);
        $loan = StaffLoan::create(['company_id' => $companyId, 'branch_id' => $branchId, 'employee_id' => $this->me->id, 'staff_loan_category_id' => $loanCategory->id, 'amount_applied' => 30000, 'amount_approved' => 30000, 'duration' => 'monthly', 'sessions' => 3, 'total_payable' => 36000, 'restoration' => 12000, 'reason' => 'School fees', 'status' => 'repaying', 'disbursed_at' => '2026-06-20 10:00:00']);
        $loan->payments()->create(['amount' => 12000, 'paid_on' => '2026-07-28']);
        StaffLoan::create(['company_id' => $companyId, 'branch_id' => $branchId, 'employee_id' => $this->other->id, 'staff_loan_category_id' => $loanCategory->id, 'amount_applied' => 50000, 'amount_approved' => 50000, 'duration' => 'monthly', 'sessions' => 2, 'total_payable' => 60000, 'restoration' => 30000, 'reason' => 'Other staff loan', 'status' => 'disbursed', 'disbursed_at' => '2026-07-01 10:00:00']);

        $advanceCategory = StaffSalaryAdvanceCategory::create(['company_id' => $companyId, 'name' => 'ADV', 'amount_from' => 0, 'amount_to' => 100000]);
        StaffSalaryAdvance::create(['company_id' => $companyId, 'branch_id' => $branchId, 'employee_id' => $this->me->id, 'staff_salary_advance_category_id' => $advanceCategory->id, 'amount' => 20000, 'recovered_amount' => 6000, 'status' => 'repaying', 'source_account' => Account::StaffFundCash->value, 'disbursed_at' => '2026-07-05 10:00:00']);
    }

    /**
     * July closes with 1,000,000 distributable profit: pool 100,000, zone share 5,000 carved out, staff pool 95,000 shared by
     * salary — me 71,250, other 23,750.
     */
    private function calculateJulyCommission(): void
    {
        $start = CarbonImmutable::parse('2026-07-01');
        $period = AccountingPeriod::create(['company_id' => $this->admin->company_id, 'period_start' => $start->toDateString(), 'period_end' => $start->endOfMonth()->toDateString(), 'status' => AccountingPeriod::STATUS_CLOSED, 'closed_at' => '2026-08-01 00:00:00']);
        BranchPeriodResult::create(['accounting_period_id' => $period->id, 'branch_id' => $this->admin->branch_id, 'net_profit' => 1000000, 'distributable_profit' => 1000000, 'commission_eligible' => true]);
        $this->actingAs($this->hr)->postJson('/api/v1/hrm/commission/calculate', ['period' => '2026-07'])->assertOk();
    }

    public function test_staff_member_sees_their_own_payslips_with_payment_status(): void
    {
        $this->seedRecords();

        $data = $this->actingAs($this->me)->getJson('/api/v1/hrm/my/payslips?employee_id='.$this->other->id)->assertOk()->json('data');

        $this->assertCount(2, $data['payslips']);
        [$august, $july] = $data['payslips'];
        $this->assertSame(['2026-08', 'awaiting_payment', 'Approved / Awaiting Payment', null, null], [$august['payroll_period'], $august['payment_status'], $august['payment_status_label'], $august['payslip_id'], $august['paid_on']]);
        $this->assertSame(['2026-07', 'July 2026', 'paid', '2026-07-28'], [$july['payroll_period'], $july['period_label'], $july['payment_status'], $july['paid_on']]);
        $this->assertEquals([300000, 41000, 60000, 6000, 12000, 2000, 80000, 201000], [$july['basic_salary'], $july['allowance'], $july['staff_fund'], $july['salary_advance'], $july['loan_restoration'], $july['other_deductions'], $july['total_deductions'], $july['net_salary']]);
        $this->assertEquals(1, $data['summary']['awaiting_payment_count']);

        // The payslip page opens for its owner only.
        $this->actingAs($this->me)->getJson("/api/v1/hrm/salary-payments/{$july['payslip_id']}")->assertOk()->assertJsonPath('data.employee_id', $this->me->id);
        $otherPayslip = SalaryPayment::where('employee_id', $this->other->id)->value('id');
        $this->actingAs($this->me)->getJson("/api/v1/hrm/salary-payments/{$otherPayslip}")->assertForbidden();
        $this->actingAs($this->me)->getJson('/api/v1/hrm/salary-payments')->assertForbidden();
    }

    public function test_staff_fund_shows_own_contributions_benefit_record_and_claims_without_the_fund_balance(): void
    {
        $this->seedRecords();

        $data = $this->actingAs($this->me)->getJson('/api/v1/hrm/my/staff-fund')->assertOk()->json('data');

        $this->assertEquals([20, 60000, 60000, 60000, 15000, 45000, 0], [
            $data['summary']['contribution_percent'], $data['summary']['total_contributions'], $data['summary']['staff_contribution'],
            $data['summary']['total_benefit_record'], $data['summary']['open_claims'], $data['summary']['claimable'], $data['summary']['benefits_paid'],
        ]);
        $this->assertArrayNotHasKey('company_contribution', $data['summary']);
        $this->assertCount(1, $data['contributions']);
        $this->assertEquals(['2026-07', 300000, 60000], [$data['contributions'][0]['payroll_period'], $data['contributions'][0]['basic_salary'], $data['contributions'][0]['staff_contribution']]);
        $this->assertCount(1, $data['claims']);
        $this->assertSame(['Medical', 'Finance Review'], [$data['claims'][0]['reason'], $data['claims'][0]['status_label']]);
    }

    public function test_commission_shows_own_period_zone_deduction_negligence_and_net_without_branch_profit(): void
    {
        $this->seedRecords();
        $this->calculateJulyCommission();

        $data = $this->actingAs($this->me)->getJson('/api/v1/hrm/my/commission')->assertOk()->json('data');

        $this->assertCount(1, $data['commissions']);
        $row = $data['commissions'][0];
        $this->assertSame(['2026-07', 'July 2026', '2026-08-01', 'calculated', 'Calculated', 'Branch Staff'], [$row['period'], $row['period_label'], $row['closing_date'], $row['status'], $row['status_label'], $row['kind_label']]);
        $this->assertEquals([71250, 5000, 30000, 41250], [$row['calculated_amount'], $row['zone_deduction'], $row['negligence_deduction'], $row['net_commission']]);
        foreach (['distributable_profit', 'commission_base', 'offset_amount', 'pool_amount', 'paying_account', 'journal_reference', 'can_approve', 'can_pay'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $row);
        }
        $this->assertEquals(41250, $data['summary']['total_net_unpaid']);
    }

    public function test_allowances_deductions_and_only_approved_negligence_are_listed(): void
    {
        $this->seedRecords();

        $allowances = $this->actingAs($this->me)->getJson('/api/v1/hrm/my/allowances')->assertOk()->json('data');
        $this->assertCount(2, $allowances['allowances']);
        $overtime = collect($allowances['allowances'])->firstWhere('reason', 'overtime');
        $this->assertSame(['Overtime Allowance', '2026-08', 'Approved / Awaiting Payroll'], [$overtime['reason_label'], $overtime['payroll_period'], $overtime['status_label']]);
        $this->assertEquals([1, 100000, 1], [$allowances['summary']['awaiting_payroll_count'], $allowances['summary']['awaiting_payroll_amount'], $allowances['summary']['pending_approval_count']]);
        $this->assertCount(1, $allowances['deductions']);
        $this->assertEquals(['Uniform', 6000], [$allowances['deductions'][0]['description'], $allowances['deductions'][0]['outstanding_amount']]);

        $negligence = $this->actingAs($this->me)->getJson('/api/v1/hrm/my/negligence')->assertOk()->json('data');
        $this->assertCount(1, $negligence['deductions']);
        $this->assertSame('Lost cash', $negligence['deductions'][0]['reason']);
        $this->assertEquals(30000, $negligence['summary']['outstanding']);
    }

    public function test_repayments_show_loan_schedule_deductions_and_advance_balance(): void
    {
        $this->seedRecords();

        $data = $this->actingAs($this->me)->getJson('/api/v1/hrm/my/repayments')->assertOk()->json('data');

        $this->assertCount(1, $data['staff_loans']);
        $loan = $data['staff_loans'][0];
        $this->assertEquals([30000, 12000, 24000, 'Repaying'], [$loan['amount_approved'], $loan['paid_amount'], $loan['outstanding'], $loan['status_label']]);
        $this->assertSame(['paid', 'due', 'due'], array_column($loan['schedule'], 'status'));
        $this->assertEquals([24000, 12000, 0], array_column($loan['schedule'], 'balance'));
        $this->assertEquals([['amount' => 12000, 'paid_on' => '2026-07-28']], $loan['deductions']);
        $this->assertCount(1, $data['salary_advances']);
        $this->assertEquals(14000, $data['salary_advances'][0]['outstanding']);
        $this->assertEquals(6000, $data['salary_advance_deductions'][0]['amount']);

        $overview = $this->actingAs($this->me)->getJson('/api/v1/hrm/my/overview')->assertOk()->json('data');
        $this->assertSame($this->me->id, $overview['employee']['id']);
        $this->assertEquals([24000, 14000, 60000, 100000, 30000], [$overview['staff_loans']['outstanding'], $overview['salary_advances']['outstanding'], $overview['staff_fund']['total_benefit_record'], $overview['allowances']['awaiting_payroll_amount'], $overview['negligence']['outstanding']]);
        $this->assertSame('awaiting_payment', $overview['salary']['latest']['payment_status']);
    }

    public function test_no_endpoint_leaks_another_employee_or_a_central_balance_whatever_ids_are_passed(): void
    {
        $this->seedRecords();
        $this->calculateJulyCommission();
        $query = http_build_query(['employee_id' => $this->other->id, 'empl_id' => $this->other->id, 'branch_id' => 'all']);

        foreach (self::ENDPOINTS as $endpoint) {
            $body = $this->actingAs($this->me)->getJson("/api/v1/hrm/my/{$endpoint}?{$query}")->assertOk()->getContent();

            foreach ([$this->other->full_name, 'Other staff', '7777777', '7857777', '999111', '888111', '66000', '44000', '23750', '17000'] as $leak) {
                $this->assertStringNotContainsString($leak, $body, "hrm/my/{$endpoint} leaks {$leak}");
            }
            foreach (['"distributable_profit"', '"pool_amount"', '"paying_account"', '"paid_from_account"', '"total_gross"', '"total_net"', '"liability"', '"members"', '"statement"'] as $key) {
                $this->assertStringNotContainsString($key, $body, "hrm/my/{$endpoint} exposes {$key}");
            }
        }

        // The other employee sees their own records.
        $this->actingAs($this->other)->getJson('/api/v1/hrm/my/negligence')->assertOk()->assertJsonPath('data.summary.outstanding', 44000);
    }

    public function test_unauthenticated_requests_are_refused(): void
    {
        $this->app['auth']->forgetGuards();

        foreach (self::ENDPOINTS as $endpoint) {
            $this->getJson("/api/v1/hrm/my/{$endpoint}")->assertUnauthorized();
        }
    }

    public function test_a_shareholder_portal_login_is_refused(): void
    {
        $shareholder = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'account_type' => Employee::ACCOUNT_SHAREHOLDER]);

        foreach (self::ENDPOINTS as $endpoint) {
            $this->actingAs($shareholder)->getJson("/api/v1/hrm/my/{$endpoint}")->assertForbidden();
        }
    }
}
