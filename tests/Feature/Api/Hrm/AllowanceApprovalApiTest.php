<?php

namespace Tests\Feature\Api\Hrm;

use App\Models\Employee;
use App\Models\HrmSetting;
use App\Models\PayrollRun;
use App\Models\StaffAllowance;
use App\Services\Approvals\SegregationOfDuties;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec §24 + §58: HR creates an allowance → Finance approves (Approved / Awaiting Payroll) → the payroll pays it once.
 */
class AllowanceApprovalApiTest extends TestCase
{
    use RefreshDatabase;

    private Employee $admin;

    private Employee $hr;

    private Employee $finance;

    private Employee $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-20 09:00:00'));
        $this->admin = $this->signInAdmin();
        HrmSetting::forCompany($this->admin->company_id)->update(['staff_fund_percent' => 0]);
        $this->hr = $this->employeeWithRole('hr');
        $this->finance = $this->employeeWithRole('finance');
        $this->staff = Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id]);
        $this->staff->salaryInfo()->create(['salary' => 500000, 'salary_type' => 'branch', 'commission_eligible' => true, 'payment_method' => 'bank', 'account_name' => 'NMB', 'account_number' => '1', 'fee' => 0]);
    }

    private function employeeWithRole(string $role): Employee
    {
        return Employee::factory()->create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->admin->branch_id,
            'role_id' => $this->admin->company->roles()->where('key', $role)->value('id'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createAllowance(array $attributes = []): StaffAllowance
    {
        $this->actingAs($this->hr)->postJson('/api/v1/hrm/allowances', $attributes + [
            'blanch_id' => $this->admin->branch_id,
            'empl_id' => $this->staff->id,
            'new_amount' => 100000,
            'reason' => 'overtime',
            'payroll_period' => '2026-07',
            'remaks_allow' => 'Month-end collections',
        ])->assertCreated();

        return StaffAllowance::latest('id')->firstOrFail();
    }

    private function allowanceOnPayroll(string $month): float
    {
        $this->actingAs($this->hr)->postJson('/api/v1/hrm/payroll/generate', ['period' => $month])->assertOk();

        return (float) PayrollRun::whereDate('period', $month.'-01')->sole()->items()->where('employee_id', $this->staff->id)->value('allowance');
    }

    private function approveAndPay(string $month): void
    {
        $run = PayrollRun::whereDate('period', $month.'-01')->sole();
        $this->actingAs($this->admin)->postJson("/api/v1/hrm/payroll/{$run->id}/approve")->assertOk();
        $this->fundPayroll($this->admin);
        $this->actingAs($this->finance)->postJson("/api/v1/hrm/payroll/{$run->id}/pay", ['ac_id' => 'interest'])->assertOk();
    }

    public function test_an_allowance_reaches_payroll_only_after_finance_approval_and_is_paid_once(): void
    {
        $this->actingAs($this->hr)->postJson('/api/v1/hrm/allowances', ['blanch_id' => $this->admin->branch_id, 'empl_id' => $this->staff->id, 'new_amount' => 100000, 'reason' => 'bonus'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $allowance = $this->createAllowance();
        $this->assertSame([StaffAllowance::STATUS_PENDING, 'overtime', '2026-07-01', $this->hr->id], [$allowance->status, $allowance->reason, $allowance->payroll_period->toDateString(), $allowance->created_by]);

        // Pending: the July payroll does not include it.
        $this->assertEquals(0, $this->allowanceOnPayroll('2026-07'));

        // HR cannot approve; neither can an HR officer holding payroll.pay who created it (rule 6).
        $this->actingAs($this->hr)->postJson("/api/v1/hrm/allowances/{$allowance->id}/approve")->assertForbidden();
        $this->hr->permissionOverrides()->create(['permission' => 'payroll.pay', 'granted' => true]);
        $this->actingAs($this->hr->fresh())->postJson("/api/v1/hrm/allowances/{$allowance->id}/approve")->assertForbidden()->assertJsonPath('message', SegregationOfDuties::INITIATOR_MESSAGE);

        $row = collect($this->actingAs($this->finance)->getJson('/api/v1/hrm/allowances')->assertOk()->json('data'))->firstWhere('id', $allowance->id);
        $this->assertSame(['Pending Finance Approval', true], [$row['status_label'], $row['can_approve']]);

        $this->actingAs($this->finance)->postJson("/api/v1/hrm/allowances/{$allowance->id}/approve")->assertOk();
        $allowance->refresh();
        $this->assertSame([StaffAllowance::STATUS_APPROVED, 'Approved / Awaiting Payroll', $this->finance->id], [$allowance->status, $allowance->statusLabel(), $allowance->approved_by]);

        // Approved: regenerating the July payroll includes it, 500,000 + 100,000.
        $this->assertEquals(100000, $this->allowanceOnPayroll('2026-07'));
        $this->assertEquals(600000, (float) PayrollRun::sole()->items()->where('employee_id', $this->staff->id)->value('gross'));
        $this->assertSame(PayrollRun::sole()->id, $allowance->fresh()->payroll_run_id);

        $this->travelTo(CarbonImmutable::parse('2026-07-25 09:00:00'));
        $this->approveAndPay('2026-07');
        $allowance->refresh();
        $this->assertSame(StaffAllowance::STATUS_PAID, $allowance->status);
        $this->assertNotNull($allowance->paid_at);

        // Paid once: the August payroll does not repeat it.
        $this->travelTo(CarbonImmutable::parse('2026-08-25 09:00:00'));
        $this->assertEquals(0, $this->allowanceOnPayroll('2026-08'));
    }

    public function test_rejected_allowances_never_reach_payroll_and_late_approvals_carry_to_the_next_payroll(): void
    {
        $rejected = $this->createAllowance(['new_amount' => 40000, 'reason' => 'transport']);
        $this->actingAs($this->finance)->postJson("/api/v1/hrm/allowances/{$rejected->id}/reject")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($this->finance)->postJson("/api/v1/hrm/allowances/{$rejected->id}/reject", ['reason' => 'Not authorised'])->assertOk();
        $this->assertSame(StaffAllowance::STATUS_REJECTED, $rejected->fresh()->status);
        $this->actingAs($this->finance)->postJson("/api/v1/hrm/allowances/{$rejected->id}/approve")->assertUnprocessable();

        // A legacy recurring allowance keeps flowing into every payroll.
        StaffAllowance::create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'employee_id' => $this->staff->id, 'amount' => 25000, 'status' => StaffAllowance::STATUS_ACTIVE, 'recurring' => true]);

        $late = $this->createAllowance(['new_amount' => 60000, 'reason' => 'leave']);
        $this->assertEquals(25000, $this->allowanceOnPayroll('2026-07'));
        $this->approveAndPay('2026-07');

        // Approved after the July payroll was paid: it waits for the next payroll instead of being lost.
        $this->actingAs($this->finance)->postJson("/api/v1/hrm/allowances/{$late->id}/approve")->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-08-25 09:00:00'));
        $this->assertEquals(85000, $this->allowanceOnPayroll('2026-08'));
        $this->approveAndPay('2026-08');
        $this->assertSame(StaffAllowance::STATUS_PAID, $late->fresh()->status);
        $this->assertSame(StaffAllowance::STATUS_ACTIVE, StaffAllowance::where('recurring', true)->sole()->status);

        // A paid allowance can no longer be stopped; a recurring one can.
        $this->actingAs($this->hr)->postJson("/api/v1/hrm/allowances/{$late->id}/stop")->assertUnprocessable();
        $recurring = StaffAllowance::where('recurring', true)->sole();
        $this->actingAs($this->hr)->postJson("/api/v1/hrm/allowances/{$recurring->id}/stop")->assertOk();
        $this->assertSame(StaffAllowance::STATUS_STOPPED, $recurring->fresh()->status);
    }
}
