<?php

namespace Tests\Feature\Api\Hrm;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\SalaryChangeRequest;
use App\Services\Approvals\SegregationOfDuties;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

/**
 * Spec §32 — a salary change is a request approved by Finance, or by Admin for an HR user's / the proposer's own salary.
 */
class SalaryChangeApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesSecondApprover;

    private Employee $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $salary, string $accountNumber = '111'): array
    {
        return ['salary' => $salary, 'account_name' => 'NMB', 'account_number' => $accountNumber, 'fee_salary' => 0, 'salary_type' => 'branch', 'commission_eligible' => true, 'payment_method' => 'bank'];
    }

    public function test_initial_salary_is_direct_and_a_change_needs_finance_approval(): void
    {
        $teller = $this->secondApprover($this->admin, 'teller');
        $hr = $this->secondApprover($this->admin, 'hr');
        $finance = $this->secondApprover($this->admin, 'finance');

        $this->actingAs($hr)->putJson("/api/v1/hrm/staff/{$teller->id}/salary", $this->payload(500000))->assertOk();
        $this->assertEquals(500000, $teller->salaryInfo()->value('salary'));

        // Banking details alone are saved directly.
        $this->actingAs($hr)->putJson("/api/v1/hrm/staff/{$teller->id}/salary", $this->payload(500000, '222'))->assertOk();
        $this->assertSame('222', $teller->salaryInfo()->value('account_number'));

        $this->actingAs($hr)->putJson("/api/v1/hrm/staff/{$teller->id}/salary", $this->payload(700000) + ['reason' => 'Promotion'])->assertStatus(202)->assertJsonPath('data.approval_stage', 'finance');
        $this->assertEquals(500000, $teller->salaryInfo()->value('salary'), 'nothing changes before approval');
        $this->actingAs($hr)->putJson("/api/v1/hrm/staff/{$teller->id}/salary", $this->payload(800000))->assertJsonValidationErrors('salary');
        $change = SalaryChangeRequest::sole();

        $this->actingAs($hr)->postJson("/api/v1/hrm/salary-changes/{$change->id}/approve")->assertForbidden();
        $this->actingAs($finance)->getJson('/api/v1/hrm/salary-changes')->assertOk()->assertJsonPath('data.pending.0.proposed_salary', 700000)->assertJsonPath('data.pending.0.can_approve', true);
        $this->actingAs($finance)->postJson("/api/v1/hrm/salary-changes/{$change->id}/approve")->assertOk();

        $this->assertEquals(700000, $teller->salaryInfo()->value('salary'));
        $change->refresh();
        $this->assertSame('approved', $change->status);
        $this->assertSame($finance->id, $change->approved_by);
        $this->assertSame($hr->id, $change->requested_by);
        $this->assertSame(
            ['EmployeeSalary.saved', 'EmployeeSalary.saved', 'EmployeeSalary.change_requested', 'EmployeeSalary.change_approved'],
            AuditLog::where('auditable_id', $teller->id)->where('action', 'like', 'EmployeeSalary.%')->orderBy('id')->pluck('action')->all(),
        );
        $this->actingAs($finance)->postJson("/api/v1/hrm/salary-changes/{$change->id}/approve")->assertJsonValidationErrors('status');
    }

    public function test_the_proposer_never_approves_their_own_proposal(): void
    {
        $teller = $this->secondApprover($this->admin, 'teller');
        $teller->salaryInfo()->create(['salary' => 300000, 'salary_type' => 'branch', 'commission_eligible' => true, 'payment_method' => 'bank', 'account_name' => 'NMB', 'account_number' => '1', 'fee' => 0]);
        $hr = $this->secondApprover($this->admin, 'hr');
        $hr->permissionOverrides()->create(['permission' => 'payroll.pay', 'granted' => true]);

        $this->actingAs($hr->fresh())->putJson("/api/v1/hrm/staff/{$teller->id}/salary", $this->payload(400000))->assertStatus(202);
        $change = SalaryChangeRequest::sole();

        $this->actingAs($hr->fresh())->postJson("/api/v1/hrm/salary-changes/{$change->id}/approve")->assertForbidden()->assertJsonPath('message', SegregationOfDuties::INITIATOR_MESSAGE);
        $this->actingAs($this->secondApprover($this->admin, 'finance'))->postJson("/api/v1/hrm/salary-changes/{$change->id}/reject", ['reason' => 'Budget'])->assertOk();
        $this->assertSame('rejected', $change->fresh()->status);
        $this->assertEquals(300000, $teller->salaryInfo()->value('salary'));
    }

    public function test_an_hr_users_salary_and_the_proposers_own_salary_need_admin_approval(): void
    {
        $hr = $this->secondApprover($this->admin, 'hr');
        $otherHr = $this->secondApprover($this->admin, 'hr');
        $finance = $this->secondApprover($this->admin, 'finance');
        $adminUser = $this->secondApprover($this->admin, 'admin');
        $hr->salaryInfo()->create(['salary' => 600000, 'salary_type' => 'hq', 'commission_eligible' => false, 'payment_method' => 'bank', 'account_name' => 'NMB', 'account_number' => '1', 'fee' => 0]);

        $this->actingAs($otherHr)->putJson("/api/v1/hrm/staff/{$hr->id}/salary", $this->payload(900000))->assertStatus(202)->assertJsonPath('data.approval_stage', 'admin');
        $change = SalaryChangeRequest::sole();
        $this->actingAs($finance)->postJson("/api/v1/hrm/salary-changes/{$change->id}/approve")->assertForbidden();
        $this->actingAs($hr)->postJson("/api/v1/hrm/salary-changes/{$change->id}/approve")->assertForbidden();
        $this->actingAs($adminUser)->postJson("/api/v1/hrm/salary-changes/{$change->id}/approve")->assertOk();
        $this->assertEquals(900000, $hr->salaryInfo()->value('salary'));

        // HR setting their own salary — even their first one — is always a request for Admin.
        $otherHr->refresh();
        $this->actingAs($otherHr)->putJson("/api/v1/hrm/staff/{$otherHr->id}/salary", $this->payload(1000000))->assertStatus(202)->assertJsonPath('data.approval_stage', 'admin');
        $own = SalaryChangeRequest::where('employee_id', $otherHr->id)->sole();
        $this->assertNull($otherHr->salaryInfo()->first());
        $this->actingAs($this->admin)->postJson("/api/v1/hrm/salary-changes/{$own->id}/approve")->assertOk();
        $this->assertEquals(1000000, $otherHr->salaryInfo()->value('salary'));
    }

    public function test_the_super_admin_may_approve_his_own_salary_change(): void
    {
        $this->admin->salaryInfo()->create(['salary' => 1000000, 'salary_type' => 'hq', 'commission_eligible' => false, 'payment_method' => 'bank', 'account_name' => 'NMB', 'account_number' => '1', 'fee' => 0]);

        $this->putJson("/api/v1/hrm/staff/{$this->admin->id}/salary", $this->payload(1200000))->assertStatus(202);
        $change = SalaryChangeRequest::sole();
        $this->postJson("/api/v1/hrm/salary-changes/{$change->id}/approve")->assertOk();
        $this->assertEquals(1200000, $this->admin->salaryInfo()->value('salary'));
    }
}
