<?php

namespace Tests\Feature\Approvals;

use App\Enums\LoanStatus;
use App\Models\ApprovalPolicy;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\StaffLoan;
use App\Models\StaffLoanCategory;
use App\Services\Approvals\SegregationOfDuties;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

/**
 * The specific segregation-of-duties result of a pending row wins over the generic row flags (loan approvals and staff credit).
 */
class PendingApprovalsBlockedFlagsTest extends TestCase
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
    private function row(Employee $viewer, string $workflow): array
    {
        $groups = collect($this->actingAs($viewer)->getJson('/api/v1/approvals/pending')->assertOk()->json('data.groups'))->keyBy('workflow');

        return $groups[$workflow]['rows'][0];
    }

    public function test_the_loan_officer_of_a_loan_is_blocked_on_its_manager_approval_row(): void
    {
        $manager = $this->secondApprover($this->admin, 'branch_manager');
        $manager->permissionOverrides()->create(['permission' => 'approvals.view', 'granted' => true]);
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id]);
        Loan::factory()->create(['customer_id' => $customer->id, 'employee_id' => $manager->id, 'status' => LoanStatus::PendingManagerApproval]);

        $blocked = $this->row($manager->fresh(), ApprovalPolicy::LOAN_APPROVALS);
        $this->assertFalse($blocked['can_approve']);
        $this->assertSame(SegregationOfDuties::INITIATOR_MESSAGE, $blocked['approve_blocked_reason']);

        $other = $this->secondApprover($this->admin, 'branch_manager');
        $other->permissionOverrides()->create(['permission' => 'approvals.view', 'granted' => true]);
        $this->assertTrue($this->row($other->fresh(), ApprovalPolicy::LOAN_APPROVALS)['can_approve']);
    }

    public function test_the_beneficiary_of_a_staff_loan_is_blocked_on_its_staff_credit_row(): void
    {
        $beneficiary = $this->secondApprover($this->admin, 'finance');
        $category = StaffLoanCategory::create(['company_id' => $this->admin->company_id, 'name' => 'SL', 'amount_from' => 1000, 'amount_to' => 10000, 'interest_rate' => 0, 'duration' => 'monthly', 'repayment_from' => 1, 'repayment_to' => 3, 'fee' => 0]);
        StaffLoan::create(['company_id' => $this->admin->company_id, 'branch_id' => $beneficiary->branch_id, 'employee_id' => $beneficiary->id, 'staff_loan_category_id' => $category->id, 'amount_applied' => 5000, 'amount_approved' => 5000, 'total_payable' => 5000, 'restoration' => 2500, 'duration' => 'monthly', 'sessions' => 2, 'reason' => 'Fees', 'status' => 'hr_approved', 'review_stage' => 'hr', 'requested_by' => $beneficiary->id]);

        $blocked = $this->row($beneficiary, ApprovalPolicy::STAFF_CREDIT);
        $this->assertStringStartsWith('FINANCE APPROVE', $blocked['description']);
        $this->assertFalse($blocked['can_approve']);
        $this->assertSame(SegregationOfDuties::INITIATOR_MESSAGE, $blocked['approve_blocked_reason']);

        $this->assertTrue($this->row($this->secondApprover($this->admin, 'finance'), ApprovalPolicy::STAFF_CREDIT)['can_approve']);
    }
}
