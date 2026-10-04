<?php

namespace Tests\Feature\Api\Hrm;

use App\Models\AccountingPeriod;
use App\Models\BranchPeriodResult;
use App\Models\CommissionAllocation;
use App\Models\Employee;
use App\Models\HrmSetting;
use App\Models\PayrollRun;
use App\Models\StaffLoan;
use App\Models\StaffLoanCategory;
use App\Models\StaffSalaryAdvance;
use App\Models\StaffSalaryAdvanceCategory;
use App\Services\LegacyImports\SpreadsheetReader;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Bank disbursement file (first_name, last_name, bank, account_number, phone_number, amount, payment_details) for money paid to
 * staff: salaries of an approved payroll, Finance Approved commission, staff loans and staff salary advances awaiting disbursement.
 */
class BankDisbursementFileTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['first_name', 'last_name', 'bank', 'account_number', 'phone_number', 'amount', 'payment_details'];

    private Employee $admin;

    private Employee $hr;

    private Employee $finance;

    private Employee $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-08-02 09:00:00'));
        $this->admin = $this->signInAdmin();
        HrmSetting::forCompany($this->admin->company_id)->update(['commission_pool_percent' => 10, 'zone_override_percent' => 0, 'staff_fund_percent' => 10]);
        $this->hr = $this->employeeWithRole('hr');
        $this->finance = $this->employeeWithRole('finance');
        $this->staff = $this->employeeWithRole('loan_officer', ['first_name' => 'Asha', 'last_name' => 'Juma', 'phone' => '0754000123']);
        $this->staff->salaryInfo()->create(['salary' => 400000, 'salary_type' => 'branch', 'commission_eligible' => true, 'payment_method' => 'bank', 'account_name' => 'ASHA JUMA', 'bank_name' => 'CRDB', 'account_number' => '0152000111', 'fee' => 0]);
    }

    public function test_approved_payroll_file_lists_each_take_home_with_the_salary_account(): void
    {
        $this->actingAs($this->hr)->postJson('/api/v1/hrm/payroll/generate', ['period' => '2026-07'])->assertOk();
        $run = PayrollRun::firstOrFail();

        $this->actingAs($this->finance)->getJson("/api/v1/hrm/payroll/{$run->id}/bank-file")->assertJsonValidationErrors('payroll');
        $this->actingAs($this->hr)->get("/api/v1/hrm/payroll/{$run->id}/bank-file")->assertForbidden();

        $this->actingAs($this->finance)->postJson("/api/v1/hrm/payroll/{$run->id}/approve")->assertOk();
        $rows = $this->rows($this->actingAs($this->finance)->get("/api/v1/hrm/payroll/{$run->id}/bank-file"));

        // 400,000 less the 10% staff fund = 360,000.
        $this->assertSame([self::HEADERS, ['Asha', 'Juma', 'CRDB', '0152000111', '0754000123', '360000', 'Salary July 2026']], $rows);
    }

    public function test_commission_file_lists_finance_approved_net_commission(): void
    {
        $period = AccountingPeriod::create(['company_id' => $this->admin->company_id, 'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'status' => AccountingPeriod::STATUS_CLOSED, 'closed_at' => '2026-08-01']);
        BranchPeriodResult::create(['accounting_period_id' => $period->id, 'branch_id' => $this->admin->branch_id, 'net_profit' => 500000, 'distributable_profit' => 500000, 'commission_eligible' => true]);
        $this->actingAs($this->hr)->postJson('/api/v1/hrm/commission/calculate', ['period' => '2026-07'])->assertOk();

        $this->assertSame([self::HEADERS], $this->rows($this->actingAs($this->finance)->get('/api/v1/hrm/commission/payments/bank-file?period=2026-07')), 'nothing is Finance Approved yet');

        CommissionAllocation::query()->update(['payment_status' => CommissionAllocation::STATUS_FINANCE_APPROVED]);
        $rows = $this->rows($this->actingAs($this->finance)->get('/api/v1/hrm/commission/payments/bank-file?period=2026-07'));

        $this->assertSame([self::HEADERS, ['Asha', 'Juma', 'CRDB', '0152000111', '0754000123', '50000', 'Commission July 2026']], $rows);
    }

    public function test_staff_loan_and_salary_advance_files_list_what_awaits_disbursement_less_the_fee(): void
    {
        $companyId = $this->admin->company_id;
        $branchId = $this->admin->branch_id;
        $loanCategory = StaffLoanCategory::create(['company_id' => $companyId, 'name' => 'L', 'amount_from' => 1000, 'amount_to' => 100000, 'interest_rate' => 20, 'duration' => 'monthly', 'repayment_from' => 1, 'repayment_to' => 3, 'fee' => 2000]);
        $loan = fn (string $status): StaffLoan => StaffLoan::create(['company_id' => $companyId, 'branch_id' => $branchId, 'employee_id' => $this->staff->id, 'staff_loan_category_id' => $loanCategory->id, 'amount_applied' => 50000, 'amount_approved' => 50000, 'fee' => 2000, 'duration' => 'monthly', 'sessions' => 2, 'total_payable' => 60000, 'restoration' => 30000, 'reason' => 'x', 'status' => $status]);
        $approved = $loan('finance_approved');
        $loan('hr_approved');
        $loan('disbursed');

        $this->assertSame(
            [self::HEADERS, ['Asha', 'Juma', 'CRDB', '0152000111', '0754000123', '48000', "Staff Loan #{$approved->id}"]],
            $this->rows($this->actingAs($this->finance)->get('/api/v1/hrm/staff-loans/bank-file')),
        );

        $advanceCategory = StaffSalaryAdvanceCategory::create(['company_id' => $companyId, 'name' => 'ADV', 'amount_from' => 0, 'amount_to' => 100000]);
        $advance = StaffSalaryAdvance::create(['company_id' => $companyId, 'branch_id' => $branchId, 'employee_id' => $this->staff->id, 'staff_salary_advance_category_id' => $advanceCategory->id, 'amount' => 30000, 'fee' => 500, 'status' => 'finance_approved']);

        $this->assertSame(
            [self::HEADERS, ['Asha', 'Juma', 'CRDB', '0152000111', '0754000123', '29500', "Salary Advance #{$advance->id}"]],
            $this->rows($this->actingAs($this->finance)->get('/api/v1/hrm/salary-advances/bank-file')),
        );
        $this->actingAs($this->hr)->get('/api/v1/hrm/salary-advances/bank-file')->assertForbidden();
    }

    public function test_salary_bank_is_saved_directly_and_must_be_a_bank_the_file_knows(): void
    {
        $payload = ['salary' => 400000, 'account_name' => 'ASHA JUMA', 'account_number' => '0152000111', 'fee_salary' => 0, 'salary_type' => 'branch', 'commission_eligible' => true, 'payment_method' => 'bank'];

        $this->actingAs($this->admin)->putJson("/api/v1/hrm/staff/{$this->staff->id}/salary", $payload + ['bank_name' => 'MY BANK'])->assertJsonValidationErrors('bank_name');
        $this->actingAs($this->admin)->putJson("/api/v1/hrm/staff/{$this->staff->id}/salary", $payload + ['bank_name' => 'NMB'])->assertSuccessful();

        $this->getJson("/api/v1/hrm/staff/{$this->staff->id}")->assertJsonPath('data.salary_info.bank_name', 'NMB');
    }

    private function employeeWithRole(string $role, array $attributes = []): Employee
    {
        return Employee::factory()->create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->admin->branch_id,
            'role_id' => $this->admin->company->roles()->where('key', $role)->value('id'),
        ] + $attributes);
    }

    /**
     * @return list<list<string>>
     */
    private function rows(TestResponse $response): array
    {
        $response->assertOk()->assertDownload();
        $path = tempnam(sys_get_temp_dir(), 'test').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        try {
            return array_map(fn (array $row): array => array_map('strval', $row), SpreadsheetReader::read($path, 'file.xlsx'));
        } finally {
            @unlink($path);
        }
    }
}
