<?php

namespace Tests\Feature\Api\Hrm;

use App\Enums\Account;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\HrmSetting;
use App\Models\PayrollRun;
use App\Models\StaffLoan;
use App\Models\StaffLoanCategory;
use App\Models\StaffSalaryAdvance;
use App\Models\StaffSalaryAdvanceCategory;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

/**
 * Spec §29/§30/§32/§49/§56 — staff credit requests: self-service, stage chain, conflict of interest and the Fund Account.
 */
class StaffCreditWorkflowApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesSecondApprover;

    private Employee $admin;

    private StaffLoanCategory $loanCategory;

    private StaffSalaryAdvanceCategory $advanceCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
        $this->loanCategory = StaffLoanCategory::create(['company_id' => $this->admin->company_id, 'name' => 'SL', 'amount_from' => 1000, 'amount_to' => 1000000, 'interest_rate' => 0, 'duration' => 'monthly', 'repayment_from' => 1, 'repayment_to' => 12, 'fee' => 0]);
        $this->advanceCategory = StaffSalaryAdvanceCategory::create(['company_id' => $this->admin->company_id, 'name' => 'SA', 'amount_from' => 1000, 'amount_to' => 500000, 'fee' => 0]);
    }

    private function employee(string $role): Employee
    {
        return $this->secondApprover($this->admin, $role);
    }

    /**
     * @return array<string, mixed>
     */
    private function loanPayload(int $amount = 50000, int $sessions = 5): array
    {
        return ['category_id' => $this->loanCategory->id, 'loan_amount' => $amount, 'day' => 'monthly', 'session' => $sessions, 'reason' => 'School fees'];
    }

    private function fund(float $amount): void
    {
        app(Ledger::class)->journal($this->admin->company_id, 'test fund', [
            ['account' => Account::StaffFundCash, 'debit' => $amount],
            ['account' => Account::StaffFund, 'employee' => $this->admin->id, 'credit' => $amount],
        ]);
    }

    private function balance(Account $account): float
    {
        return app(Ledger::class)->balance($this->admin->company_id, $account);
    }

    public function test_any_staff_member_requests_a_loan_and_an_advance_for_themselves_only(): void
    {
        $teller = $this->employee('teller');
        $other = $this->employee('teller');

        $this->actingAs($teller)->postJson('/api/v1/hrm/staff-loans', $this->loanPayload() + ['blanch_id' => $teller->branch_id, 'empl_id' => $teller->id])->assertForbidden();
        $this->actingAs($teller)->postJson('/api/v1/hrm/my/staff-loans', $this->loanPayload() + ['empl_id' => $other->id])->assertCreated();
        $this->actingAs($teller)->postJson('/api/v1/hrm/my/salary-advances', ['fee' => $this->advanceCategory->id, 'advance_amount' => 30000, 'empl_id' => $other->id])->assertCreated();
        $this->actingAs($teller)->postJson('/api/v1/hrm/my/salary-advances', ['fee' => $this->advanceCategory->id, 'advance_amount' => 900000])->assertJsonValidationErrors('advance_amount');

        $loan = StaffLoan::sole();
        $this->assertSame($teller->id, $loan->employee_id);
        $this->assertSame($teller->id, $loan->requested_by);
        $this->assertSame('submitted', $loan->status);
        $this->assertSame('hr', $loan->review_stage);
        $this->assertSame($teller->id, StaffSalaryAdvance::sole()->employee_id);

        $this->actingAs($teller)->getJson('/api/v1/hrm/my/staff-loans')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'submitted')->assertJsonPath('data.0.can_approve', false);
        $this->actingAs($teller)->getJson('/api/v1/hrm/my/salary-advances')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($other)->getJson('/api/v1/hrm/my/staff-loans')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($teller)->getJson('/api/v1/hrm/my/staff-credit-categories')->assertOk()->assertJsonPath('data.loan.0.id', $this->loanCategory->id);

        // HR's on-behalf entry keeps working.
        $hr = $this->employee('hr');
        $this->actingAs($hr)->postJson('/api/v1/hrm/staff-loans', $this->loanPayload() + ['blanch_id' => $other->branch_id, 'empl_id' => $other->id])->assertCreated();
        $this->assertSame($hr->id, StaffLoan::where('employee_id', $other->id)->sole()->requested_by);
    }

    public function test_full_status_chain_records_who_and_when_and_finance_approval_is_separate_from_disbursement(): void
    {
        $teller = $this->employee('teller');
        $hr = $this->employee('hr');
        $finance = $this->employee('finance');
        $this->fund(100000);

        $this->actingAs($teller)->postJson('/api/v1/hrm/my/staff-loans', $this->loanPayload(50000, 5))->assertCreated();
        $loan = StaffLoan::sole();

        // Stages can not be skipped, and HR never takes Finance's steps.
        $this->actingAs($finance)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/disburse")->assertJsonValidationErrors('status');
        $this->actingAs($finance)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/finance-approve")->assertJsonValidationErrors('status');
        $this->actingAs($finance)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/approve")->assertForbidden();

        $this->travelTo(now()->parse('2026-09-01 09:00'));
        $this->actingAs($hr)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/approve")->assertOk();
        $this->actingAs($hr)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/finance-approve")->assertForbidden();
        $this->actingAs($hr)->getJson('/api/v1/hrm/staff-loans')->assertOk()->assertJsonPath('data.approved.0.status', 'hr_approved');

        $this->travelTo(now()->parse('2026-09-02 09:00'));
        $this->actingAs($finance)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/finance-approve")->assertOk();
        $this->assertSame('finance_approved', $loan->fresh()->status);
        $this->assertEquals(100000, $this->balance(Account::StaffFundCash), 'Finance approval moves no money');
        $this->actingAs($finance)->getJson('/api/v1/hrm/staff-loans')->assertJsonPath('data.approved.0.next_action', 'disburse');

        $this->travelTo(now()->parse('2026-09-03 09:00'));
        $this->actingAs($finance)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/disburse")->assertOk();
        $this->assertEquals(50000, $this->balance(Account::StaffFundCash));

        $loan->refresh();
        $this->assertSame('disbursed', $loan->status);
        $this->assertSame($teller->id, $loan->requested_by);
        $this->assertSame($hr->id, $loan->approved_by);
        $this->assertSame('2026-09-01 09:00:00', $loan->approved_at->toDateTimeString());
        $this->assertSame($finance->id, $loan->finance_approved_by);
        $this->assertSame('2026-09-02 09:00:00', $loan->finance_approved_at->toDateTimeString());
        $this->assertSame($finance->id, $loan->disbursed_by);
        $this->assertSame('2026-09-03 09:00:00', $loan->disbursed_at->toDateTimeString());

        $this->actingAs($finance)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/pay", ['amount' => 10000])->assertOk();
        $this->assertSame('repaying', $loan->fresh()->status);
        $this->actingAs($finance)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/pay", ['amount' => 40000])->assertOk();
        $this->assertSame('completed', $loan->fresh()->status);
        $this->assertNotNull($loan->fresh()->completed_at);

        $this->actingAs($hr)->getJson('/api/v1/hrm/staff-loans')->assertJsonPath('data.disbursed.0.finance_approved_by_name', $finance->full_name)->assertJsonPath('data.disbursed.0.status_label', 'Completed');
        $this->assertSame(
            ['StaffLoan.submitted', 'StaffLoan.hr_approved', 'StaffLoan.finance_approved', 'StaffLoan.disbursed'],
            AuditLog::where('auditable_type', $loan->getMorphClass())->where('auditable_id', $loan->id)->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_the_requester_or_beneficiary_never_approves_any_stage(): void
    {
        $beneficiary = $this->employee('finance');
        $requester = $this->employee('hr');
        $requester->permissionOverrides()->create(['permission' => 'payroll.pay', 'granted' => true]);
        $requester = $requester->fresh();
        $hr = $this->employee('hr');
        $finance = $this->employee('finance');
        $this->fund(100000);

        $this->actingAs($requester)->postJson('/api/v1/hrm/staff-loans', $this->loanPayload() + ['blanch_id' => $beneficiary->branch_id, 'empl_id' => $beneficiary->id])->assertCreated();
        $loan = StaffLoan::sole();

        $this->actingAs($requester)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/approve")->assertForbidden()->assertJsonPath('message', SegregationOfDuties::INITIATOR_MESSAGE);
        $this->actingAs($hr)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/approve")->assertOk();

        $this->actingAs($beneficiary)->getJson('/api/v1/hrm/staff-loans')->assertJsonPath('data.approved.0.can_approve', false)->assertJsonPath('data.approved.0.approve_blocked_reason', SegregationOfDuties::INITIATOR_MESSAGE);
        $this->actingAs($beneficiary)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/finance-approve")->assertForbidden();
        $this->actingAs($requester)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/finance-approve")->assertForbidden();
        $this->actingAs($finance)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/finance-approve")->assertOk();

        $this->actingAs($beneficiary)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/disburse")->assertForbidden();
        $this->actingAs($requester)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/disburse")->assertForbidden();
        $this->assertSame('finance_approved', $loan->fresh()->status);
        $this->actingAs($finance)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/disburse")->assertOk();
    }

    public function test_an_hr_self_request_goes_to_admin_then_finance(): void
    {
        $hr = $this->employee('hr');
        $otherHr = $this->employee('hr');
        $adminUser = $this->employee('admin');
        $finance = $this->employee('finance');
        $this->fund(100000);

        $this->actingAs($hr)->postJson('/api/v1/hrm/my/salary-advances', ['fee' => $this->advanceCategory->id, 'advance_amount' => 40000])->assertCreated();
        $advance = StaffSalaryAdvance::sole();
        $this->assertSame('admin', $advance->review_stage);

        $this->actingAs($hr)->postJson("/api/v1/hrm/salary-advances/{$advance->id}/approve")->assertForbidden();
        $this->actingAs($otherHr)->postJson("/api/v1/hrm/salary-advances/{$advance->id}/approve")->assertForbidden();
        $this->actingAs($finance)->postJson("/api/v1/hrm/salary-advances/{$advance->id}/finance-approve")->assertJsonValidationErrors('status');

        $this->actingAs($adminUser)->getJson('/api/v1/hrm/salary-advances')->assertOk()->assertJsonPath('data.pending.0.next_action', 'admin_approve')->assertJsonPath('data.pending.0.can_approve', true);
        $pending = collect($this->actingAs($adminUser)->getJson('/api/v1/approvals/pending')->assertOk()->json('data.groups'))->keyBy('workflow');
        $this->assertTrue($pending['hrm.staff_credit']['rows'][0]['can_approve']);

        $this->actingAs($adminUser)->postJson("/api/v1/hrm/salary-advances/{$advance->id}/approve")->assertOk();
        $this->assertSame('admin_approved', $advance->fresh()->status);
        $this->assertSame($adminUser->id, $advance->fresh()->approved_by);

        $this->actingAs($finance)->postJson("/api/v1/hrm/salary-advances/{$advance->id}/finance-approve")->assertOk();
        $this->actingAs($finance)->postJson("/api/v1/hrm/salary-advances/{$advance->id}/disburse")->assertOk();
        $this->assertSame('disbursed', $advance->fresh()->status);
        $this->assertEquals(60000, $this->balance(Account::StaffFundCash));
    }

    public function test_the_super_admin_may_approve_his_own_request_at_every_stage(): void
    {
        $this->fund(100000);

        $this->postJson('/api/v1/hrm/my/staff-loans', $this->loanPayload())->assertCreated();
        $loan = StaffLoan::sole();
        $this->assertSame('admin', $loan->review_stage);

        $this->postJson("/api/v1/hrm/staff-loans/{$loan->id}/approve")->assertOk();
        $this->postJson("/api/v1/hrm/staff-loans/{$loan->id}/finance-approve")->assertOk();
        $this->postJson("/api/v1/hrm/staff-loans/{$loan->id}/disburse")->assertOk();

        $this->assertSame('disbursed', $loan->fresh()->status);
        $this->assertSame($this->admin->id, $loan->fresh()->disbursed_by);
    }

    public function test_new_salary_advances_can_not_be_paid_from_the_company_account(): void
    {
        $teller = $this->employee('teller');
        $finance = $this->employee('finance');
        $this->actingAs($teller)->postJson('/api/v1/hrm/my/salary-advances', ['fee' => $this->advanceCategory->id, 'advance_amount' => 30000])->assertCreated();
        $advance = StaffSalaryAdvance::sole();
        $this->actingAs($this->employee('hr'))->postJson("/api/v1/hrm/salary-advances/{$advance->id}/approve")->assertOk();
        $this->actingAs($finance)->postJson("/api/v1/hrm/salary-advances/{$advance->id}/finance-approve")->assertOk();

        $this->actingAs($finance)->postJson("/api/v1/hrm/salary-advances/{$advance->id}/disburse", ['ac_id' => Account::Company->value])->assertJsonValidationErrors('ac_id');
        $this->actingAs($finance)->postJson("/api/v1/hrm/salary-advances/{$advance->id}/disburse")->assertJsonValidationErrors('amount');
        $this->assertSame('finance_approved', $advance->fresh()->status);
        $this->assertEquals(0, $this->balance(Account::Company));

        $this->fund(30000);
        $this->actingAs($finance)->postJson("/api/v1/hrm/salary-advances/{$advance->id}/disburse")->assertOk();
        $this->assertSame(Account::StaffFundCash->value, $advance->fresh()->source_account);
        $this->assertEquals(0, $this->balance(Account::StaffFundCash));
    }

    public function test_rejection_records_who_when_and_why(): void
    {
        $teller = $this->employee('teller');
        $hr = $this->employee('hr');
        $this->actingAs($teller)->postJson('/api/v1/hrm/my/staff-loans', $this->loanPayload())->assertCreated();
        $loan = StaffLoan::sole();

        $this->actingAs($hr)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/reject", ['reason' => 'Too many loans'])->assertOk();
        $loan->refresh();
        $this->assertSame('rejected', $loan->status);
        $this->assertSame($hr->id, $loan->rejected_by);
        $this->assertNotNull($loan->rejected_at);
        $this->assertSame('Too many loans', $loan->rejection_reason);
        $this->actingAs($hr)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/approve")->assertJsonValidationErrors('status');
    }

    public function test_section_56_loan_reduces_the_fund_and_the_payroll_deduction_increases_it(): void
    {
        HrmSetting::forCompany($this->admin->company_id)->update(['staff_fund_percent' => 20]);
        $teller = $this->employee('teller');
        $teller->salaryInfo()->create(['salary' => 1000000, 'salary_type' => 'branch', 'commission_eligible' => true, 'payment_method' => 'bank', 'account_name' => 'NMB', 'account_number' => '1', 'fee' => 0]);
        $hr = $this->employee('hr');
        $finance = $this->employee('finance');
        $this->fund(600000);

        $this->actingAs($teller)->postJson('/api/v1/hrm/my/staff-loans', $this->loanPayload(500000, 5))->assertCreated();
        $loan = StaffLoan::sole();
        $this->actingAs($hr)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/approve")->assertOk();
        $this->assertEquals(100000, $loan->fresh()->restoration);
        $this->actingAs($finance)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/finance-approve")->assertOk();
        $this->actingAs($finance)->postJson("/api/v1/hrm/staff-loans/{$loan->id}/disburse")->assertOk();
        $this->assertEquals(100000, $this->balance(Account::StaffFundCash), 'the fund decreases by the 500,000 disbursed');

        $period = now()->subMonthNoOverflow()->format('Y-m');
        $this->actingAs($hr)->postJson('/api/v1/hrm/payroll/generate', ['period' => $period])->assertOk();
        $run = PayrollRun::sole();
        $this->actingAs($finance)->postJson("/api/v1/hrm/payroll/{$run->id}/approve")->assertOk();
        $this->fundPayroll($finance);
        $this->actingAs($finance)->postJson("/api/v1/hrm/payroll/{$run->id}/pay", ['ac_id' => 'interest'])->assertOk();

        $this->assertEquals(100000, $loan->payments()->sum('amount'));
        $this->assertSame('repaying', $loan->fresh()->status);
        // Fund: 100,000 + the 100,000 loan deduction + the 20% staff fund contribution (200,000) of the 1,000,000 basic salary.
        $this->assertEquals(400000, $this->balance(Account::StaffFundCash));
    }

    public function test_hr_sees_member_records_but_not_the_fund_account_cash(): void
    {
        $this->fund(50000);

        $hrReport = $this->actingAs($this->employee('hr'))->getJson('/api/v1/hrm/staff-fund')->assertOk()->json('data');
        $this->assertArrayHasKey('members', $hrReport);
        $this->assertArrayHasKey('total_benefit_record', $hrReport);
        foreach (['balance', 'statement', 'opening_balance', 'loans_issued', 'advances_issued', 'repayments', 'withdrawals'] as $key) {
            $this->assertArrayNotHasKey($key, $hrReport, $key);
        }

        $this->actingAs($this->employee('finance'))->getJson('/api/v1/hrm/staff-fund')->assertOk()->assertJsonPath('data.balance', 50000)->assertJsonStructure(['data' => ['statement', 'members']]);
        $this->actingAs($this->employee('teller'))->getJson('/api/v1/hrm/staff-fund')->assertForbidden();
    }
}
