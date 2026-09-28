<?php

namespace Tests\Feature\Api\Hrm;

use App\Enums\Account;
use App\Models\Employee;
use App\Models\StaffLoan;
use App\Models\StaffLoanCategory;
use App\Models\StaffSalaryAdvance;
use App\Models\StaffSalaryAdvanceCategory;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

class StaffCreditApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesSecondApprover;

    private Employee $admin;

    private Employee $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
        $this->staff = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id]);
    }

    /**
     * A non-exempt initiator: HR (hrm.manage) also granted payroll.pay by employee override.
     */
    private function requesterWhoCanPay(): Employee
    {
        $requester = $this->secondApprover($this->admin, 'hr');
        $requester->permissionOverrides()->create(['permission' => 'payroll.pay', 'granted' => true]);

        return $requester->fresh();
    }

    private function balance(Account $account, ?int $employee = null): float
    {
        return app(Ledger::class)->balance($this->admin->company_id, $account, employee: $employee);
    }

    private function fundTheStaffFund(float $amount): void
    {
        app(Ledger::class)->journal($this->admin->company_id, 'test contribution', [
            ['account' => Account::StaffFundCash, 'debit' => $amount],
            ['account' => Account::StaffFund, 'employee' => $this->staff->id, 'credit' => $amount],
        ]);
    }

    public function test_leave_allowance_deduction_and_categories(): void
    {
        $this->postJson('/api/v1/hrm/leaves', ['empl_id' => $this->staff->id, 'stat_date' => '2026-09-01', 'end_date' => '2026-09-10', 'remaks' => 'Annual'])->assertCreated();
        $this->postJson('/api/v1/hrm/leaves', ['empl_id' => $this->staff->id, 'stat_date' => '2026-09-10', 'end_date' => '2026-09-01', 'remaks' => 'x'])->assertJsonValidationErrors('end_date');
        $leave = $this->getJson('/api/v1/hrm/leaves')->assertOk()->json('data.0');
        $this->postJson("/api/v1/hrm/leaves/{$leave['id']}/decide", ['status' => 'approved'])->assertOk();

        $this->postJson('/api/v1/hrm/allowances', ['blanch_id' => $this->staff->branch_id, 'empl_id' => $this->staff->id, 'new_amount' => 10000, 'remaks_allow' => 'Transport'])->assertCreated();
        $this->postJson('/api/v1/hrm/deductions', ['blanch_id' => $this->staff->branch_id, 'empl_id' => $this->staff->id, 'amount' => 30000, 'instalment' => 3, 'description' => 'Uniform'])->assertCreated();
        $this->getJson('/api/v1/hrm/allowances')->assertJsonPath('data.0.description', 'Transport');
        $this->getJson('/api/v1/hrm/deductions?branch_id=all&from=2020-01-01&to=2020-01-02')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/hrm/deductions')->assertJsonPath('data.0.instalment_amount', 10000);

        $this->postJson('/api/v1/hrm/staff-loan-categories', ['category_name' => 'TEST1', 'from_amount' => 1000, 'to_amount' => 10000, 'interest' => 20, 'duration' => 'monthly', 'from_repayment' => 1, 'to_repayment' => 3, 'fee' => 0])->assertCreated();
        $category = StaffLoanCategory::firstOrFail();
        $this->putJson("/api/v1/hrm/staff-loan-categories/{$category->id}", ['category_name' => 'TEST2', 'from_amount' => 1000, 'to_amount' => 20000, 'interest' => 10, 'duration' => 'weekly', 'from_repayment' => 1, 'to_repayment' => 4, 'fee' => 100])->assertOk();
        $this->assertSame('TEST2', $category->fresh()->name);
        $this->deleteJson("/api/v1/hrm/staff-loan-categories/{$category->id}")->assertOk();

        $this->postJson('/api/v1/hrm/staff-salary-advance-categories', ['cate_name' => 'SALARY ADVANCE STAFF', 'from_amount' => 10000, 'to_amount' => 100000, 'fee' => 200])->assertCreated();
        $this->getJson('/api/v1/hrm/staff-salary-advance-categories')->assertJsonPath('data.0.fee', 200);
    }

    public function test_staff_loan_hr_approves_finance_disburses_from_staff_fund_and_repayment(): void
    {
        $category = StaffLoanCategory::create(['company_id' => $this->admin->company_id, 'name' => 'TEST1', 'amount_from' => 1000, 'amount_to' => 10000, 'interest_rate' => 20, 'duration' => 'monthly', 'repayment_from' => 1, 'repayment_to' => 3, 'fee' => 0]);
        $payload = ['blanch_id' => $this->staff->branch_id, 'empl_id' => $this->staff->id, 'category_id' => $category->id, 'loan_amount' => 10000, 'day' => 'monthly', 'session' => 2, 'reason' => 'School fees'];
        // Requested by HR (also able to pay) so rule 6 — not a permission — blocks the requester below.
        $requester = $this->requesterWhoCanPay();
        $this->actingAs($requester);

        $this->postJson('/api/v1/hrm/staff-loans', ['loan_amount' => 50000] + $payload)->assertJsonValidationErrors('loan_amount');
        $this->postJson('/api/v1/hrm/staff-loans', $payload)->assertCreated();
        $loan = StaffLoan::firstOrFail();

        // Rule 6: the requester approves nothing; a second user approves and a third disburses.
        $this->postJson("/api/v1/hrm/staff-loans/{$loan->id}/approve")->assertForbidden();
        $approver = $this->secondApprover($this->admin);
        $payer = $this->secondApprover($this->admin);
        $this->actingAs($approver)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/approve")->assertOk();
        $this->actingAs($this->admin);
        $loan->refresh();
        $this->assertSame('hr_approved', $loan->status);
        $this->assertEquals(12000, $loan->total_payable);
        $this->assertEquals(6000, $loan->restoration);

        $this->actingAs($requester)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/finance-approve")->assertForbidden();
        $this->actingAs($payer)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/finance-approve")->assertOk();
        $this->assertSame('finance_approved', $loan->fresh()->status);
        $this->actingAs($payer)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/disburse")->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->fundTheStaffFund(15000);

        $hr = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'role_id' => $this->admin->company->roles()->where('key', 'hr')->value('id')]);
        $this->actingAs($hr)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/disburse")->assertForbidden();
        $this->actingAs($requester)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/disburse")->assertForbidden();
        $this->actingAs($payer)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/disburse")->assertOk();
        $this->actingAs($this->admin);

        $this->assertSame('disbursed', $loan->fresh()->status);
        $this->assertEquals(5000, $this->balance(Account::StaffFundCash));
        $this->assertEquals(10000, $this->balance(Account::StaffLoanReceivable, $this->staff->id));

        $this->postJson("/api/v1/hrm/staff-loans/{$loan->id}/pay", ['amount' => 20000])->assertJsonValidationErrors('amount');
        $this->postJson("/api/v1/hrm/staff-loans/{$loan->id}/pay", ['amount' => 6000])->assertOk();
        $this->assertEquals(11000, $this->balance(Account::StaffFundCash));
        $this->assertEquals(5000, $this->balance(Account::StaffLoanReceivable, $this->staff->id));
        $this->getJson('/api/v1/hrm/staff-loans/active')->assertJsonPath('data.0.remaining_amount', 6000);

        $this->postJson("/api/v1/hrm/staff-loans/{$loan->id}/pay", ['amount' => 6000])->assertOk();
        $this->assertSame('completed', $loan->fresh()->status);
    }

    public function test_salary_advance_request_approve_disburse_and_reject(): void
    {
        $category = StaffSalaryAdvanceCategory::create(['company_id' => $this->admin->company_id, 'name' => 'SALARY ADVANCE STAFF', 'amount_from' => 10000, 'amount_to' => 100000, 'fee' => 200]);
        $payload = ['blanch_id' => $this->staff->branch_id, 'empl_id' => $this->staff->id, 'fee' => $category->id];

        // Requested by HR (also able to pay) so rule 6 — not a permission — blocks the requester below.
        $requester = $this->requesterWhoCanPay();
        $this->actingAs($requester);
        $this->postJson('/api/v1/hrm/salary-advances', $payload + ['advance_amount' => 500])->assertJsonValidationErrors('advance_amount');
        $this->postJson('/api/v1/hrm/salary-advances', $payload + ['advance_amount' => 20000])->assertCreated();
        $advance = StaffSalaryAdvance::firstOrFail();

        $this->postJson("/api/v1/hrm/salary-advances/{$advance->id}/disburse", ['ac_id' => 'staff_fund_cash'])->assertUnprocessable();

        // Rule 6: the requester approves nothing; second users approve, Finance approves and disburses from the fund only.
        $this->postJson("/api/v1/hrm/salary-advances/{$advance->id}/approve")->assertForbidden();
        $this->approveAsSecondUser($requester, "/api/v1/hrm/salary-advances/{$advance->id}/approve");
        $this->approveAsSecondUser($requester, "/api/v1/hrm/salary-advances/{$advance->id}/finance-approve");
        $this->fundTheStaffFund(30000);
        $this->asApprover($requester, fn () => $this->postJson("/api/v1/hrm/salary-advances/{$advance->id}/disburse", ['ac_id' => 'company_cash'])->assertJsonValidationErrors('ac_id'));
        $this->approveAsSecondUser($requester, "/api/v1/hrm/salary-advances/{$advance->id}/disburse", ['ac_id' => 'staff_fund_cash']);

        $this->assertSame('disbursed', $advance->fresh()->status);
        $this->assertSame('staff_fund_cash', $advance->fresh()->source_account);
        $this->assertEquals(0, $this->balance(Account::Company));
        $this->assertEquals(10200, $this->balance(Account::StaffFundCash));
        $this->assertEquals(20000, $this->balance(Account::StaffAdvanceReceivable, $this->staff->id));
        $this->getJson('/api/v1/hrm/salary-advances')->assertJsonPath('data.disbursed.0.outstanding_amount', 20000);

        $other = StaffSalaryAdvance::create(['company_id' => $this->admin->company_id, 'branch_id' => $this->staff->branch_id, 'employee_id' => $this->staff->id, 'staff_salary_advance_category_id' => $category->id, 'amount' => 15000, 'status' => 'submitted']);
        $this->postJson("/api/v1/hrm/salary-advances/{$other->id}/reject")->assertOk();
        $this->assertSame('rejected', $other->fresh()->status);
    }

    public function test_attendance_check_in_out_summary_and_performance(): void
    {
        $this->travelTo(now()->parse('2026-09-10 09:30:00'));
        $this->postJson('/api/v1/hrm/attendance/check-in', ['empl_id' => $this->staff->id])->assertOk()->assertJsonPath('data.status', 'late');
        $this->postJson('/api/v1/hrm/attendance/check-in', ['empl_id' => $this->staff->id])->assertUnprocessable();
        $this->travelTo(now()->setTime(17, 30));
        $this->postJson('/api/v1/hrm/attendance/check-out', ['empl_id' => $this->staff->id])->assertOk();
        $this->postJson('/api/v1/hrm/attendance/check-in')->assertOk();

        $row = collect($this->getJson('/api/v1/hrm/attendance')->assertOk()->json('data.rows'))->firstWhere('employee_id', $this->staff->id);
        $this->assertSame('late', $row['status']);
        $this->assertEquals(8, $row['hours']);

        $this->postJson('/api/v1/hrm/attendance', ['empl_id' => $this->staff->id, 'date' => now()->toDateString(), 'check_in' => '07:50', 'check_out' => '17:00', 'status' => 'present'])->assertOk();
        $summary = collect($this->getJson('/api/v1/hrm/attendance/summary?month='.now()->format('Y-m'))->assertOk()->json('data.rows'))->firstWhere('employee_id', $this->staff->id);
        $this->assertSame(1, $summary['present']);

        $this->postJson('/api/v1/hrm/performance/reviews', ['empl_id' => $this->staff->id, 'period' => now()->format('Y-m'), 'rating' => 4, 'discipline' => 'Good'])->assertCreated();
        $this->postJson('/api/v1/hrm/performance/reviews', ['empl_id' => $this->staff->id, 'period' => now()->format('Y-m'), 'rating' => 9])->assertJsonValidationErrors('rating');
        $this->getJson('/api/v1/hrm/performance')->assertOk()->assertJsonStructure(['data' => ['rows']]);

        $teller = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'role_id' => $this->admin->company->roles()->where('key', 'teller')->value('id')]);
        $this->actingAs($teller)->getJson('/api/v1/hrm/attendance')->assertForbidden();
        $this->actingAs($teller)->postJson('/api/v1/hrm/attendance/check-in')->assertOk();
        $this->actingAs($teller)->postJson('/api/v1/hrm/attendance/check-in', ['empl_id' => $this->staff->id])->assertForbidden();
    }
}
