<?php

namespace Tests\Feature\Approvals;

use App\Enums\LoanStatus;
use App\Models\ApprovalPolicy;
use App\Models\BankAccount;
use App\Models\BankTransfer;
use App\Models\Branch;
use App\Models\Capital;
use App\Models\Customer;
use App\Models\DividendDeclarationRequest;
use App\Models\Employee;
use App\Models\ExpenseRequest;
use App\Models\ExpenseType;
use App\Models\FloatTransfer;
use App\Models\HqTransaction;
use App\Models\LegacyImport;
use App\Models\Loan;
use App\Models\NegligenceDeduction;
use App\Models\Payment;
use App\Models\PayrollRun;
use App\Models\ReversalRequest;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvanceCategory;
use App\Models\ShareHolder;
use App\Models\ShareIssuanceRequest;
use App\Models\StaffAllowance;
use App\Models\StaffLoan;
use App\Models\StaffLoanCategory;
use App\Models\StaffSalaryAdvance;
use App\Models\StaffSalaryAdvanceCategory;
use App\Models\TellerDeposit;
use App\Models\WriteOffRequest;
use App\Services\Approvals\PendingApprovals;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\Assets\AssetRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

/**
 * C6 Pending Approvals: GET /approvals/pending lists every workflow's pending items for users holding approvals.view (and the
 * module permission), with company isolation, branch scope and the segregation-of-duties flags.
 */
class PendingApprovalsTest extends TestCase
{
    use RefreshDatabase;
    use UsesSecondApprover;

    private Employee $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
    }

    public function test_approvals_view_gates_the_list_and_is_granted_to_finance_admin_and_super_admin_only(): void
    {
        $this->assertArrayHasKey('approvals.view', config('permissions.permissions'));
        $this->getJson('/api/v1/approvals/pending')->assertOk();
        $this->actingAs($this->secondApprover($this->admin, 'admin'))->getJson('/api/v1/approvals/pending')->assertOk();
        $this->actingAs($this->secondApprover($this->admin, 'finance'))->getJson('/api/v1/approvals/pending')->assertOk();

        foreach (['teller', 'loan_officer', 'branch_manager', 'credit_officer', 'hr', 'zone_manager'] as $role) {
            $this->actingAs($this->secondApprover($this->admin, $role))->getJson('/api/v1/approvals/pending')->assertForbidden();
        }

        $teller = $this->secondApprover($this->admin, 'teller');
        $teller->permissionOverrides()->create(['permission' => 'approvals.view', 'granted' => true]);
        $this->actingAs($teller->fresh())->getJson('/api/v1/approvals/pending')->assertOk()->assertJsonPath('data.groups', []);
    }

    public function test_the_list_contains_pending_items_of_every_workflow_with_links_and_segregation_flags(): void
    {
        $initiator = $this->secondApprover($this->admin, 'admin');
        $initiator->permissionOverrides()->createMany([
            ['permission' => 'capital.manage', 'granted' => true],
            ['permission' => 'shares.issue', 'granted' => true],
            ['permission' => 'payments.verify', 'granted' => true],
        ]);
        $initiator = $initiator->fresh();
        $this->seedPending($initiator, $this->admin->branch_id);

        $groups = collect($this->getJson('/api/v1/approvals/pending')->assertOk()->json('data.groups'))->keyBy('workflow');
        $this->assertSame(array_keys(PendingApprovals::GROUPS), $groups->keys()->all(), 'every workflow, in display order');
        foreach (PendingApprovals::GROUPS as $workflow => $label) {
            $this->assertSame($label, $groups[$workflow]['label']);
            $this->assertGreaterThanOrEqual(1, $groups[$workflow]['count'], "{$workflow} has its pending item");
            foreach ($groups[$workflow]['rows'] as $row) {
                $this->assertSame(['workflow', 'workflow_label', 'id', 'description', 'branch', 'amount', 'requested_by', 'requested_at', 'status', 'link', 'can_approve', 'approve_blocked_reason'], array_keys($row));
                $this->assertStringStartsWith('/', $row['link']);
            }
        }
        $this->assertSame('/capital/assets/'.$groups[ApprovalPolicy::ASSET_CONTRIBUTIONS]['rows'][0]['id'], $groups[ApprovalPolicy::ASSET_CONTRIBUTIONS]['rows'][0]['link']);
        $this->assertSame(1, $this->getJson('/api/v1/approvals/pending')->json('data.groups.0.count'));

        // Another authorised user (the Super Admin) may approve them.
        foreach ([ApprovalPolicy::FLOATS, ApprovalPolicy::BANK_TRANSFERS, ApprovalPolicy::HQ_TRANSACTIONS, ApprovalPolicy::CAPITAL_CONTRIBUTIONS, ApprovalPolicy::ASSET_CONTRIBUTIONS, ApprovalPolicy::SHARE_ISSUANCES, ApprovalPolicy::DIVIDEND_DECLARATIONS, ApprovalPolicy::WRITE_OFFS, ApprovalPolicy::REVERSAL_REQUESTS, ApprovalPolicy::SALARY_ADVANCES, ApprovalPolicy::TELLER_DEPOSITS, ApprovalPolicy::BRANCH_RECEIPTS, ApprovalPolicy::LOAN_APPROVALS] as $workflow) {
            $this->assertTrue($groups[$workflow]['rows'][0]['can_approve'], $workflow);
        }

        // The initiator (Admin) is blocked on the rows they initiated.
        $this->actingAs($initiator);
        $own = collect($this->getJson('/api/v1/approvals/pending')->assertOk()->json('data.groups'))->keyBy('workflow');
        foreach ([ApprovalPolicy::FLOATS, ApprovalPolicy::CAPITAL_CONTRIBUTIONS, ApprovalPolicy::ASSET_CONTRIBUTIONS, ApprovalPolicy::SHARE_ISSUANCES, ApprovalPolicy::DIVIDEND_DECLARATIONS, ApprovalPolicy::WRITE_OFFS, ApprovalPolicy::REVERSAL_REQUESTS, ApprovalPolicy::BRANCH_RECEIPTS] as $workflow) {
            $this->assertFalse($own[$workflow]['rows'][0]['can_approve'], $workflow);
            $this->assertSame(SegregationOfDuties::INITIATOR_MESSAGE, $own[$workflow]['rows'][0]['approve_blocked_reason'], $workflow);
        }

        // The company policy + explicit grant lift the block for the initiator.
        $this->grantSelfApproval($initiator);
        $own = collect($this->getJson('/api/v1/approvals/pending')->json('data.groups'))->keyBy('workflow');
        $this->assertTrue($own[ApprovalPolicy::FLOATS]['rows'][0]['can_approve']);
        $this->assertNull($own[ApprovalPolicy::FLOATS]['rows'][0]['approve_blocked_reason']);
    }

    public function test_finance_sees_only_the_workflows_of_its_modules(): void
    {
        $this->seedPending($this->admin, $this->admin->branch_id);

        $workflows = array_column($this->actingAs($this->secondApprover($this->admin, 'finance'))->getJson('/api/v1/approvals/pending')->assertOk()->json('data.groups'), 'workflow');

        $this->assertContains(ApprovalPolicy::FLOATS, $workflows);
        $this->assertContains(ApprovalPolicy::BRANCH_RECEIPTS, $workflows);
        $this->assertContains(ApprovalPolicy::TELLER_DEPOSITS, $workflows);
        $this->assertContains(ApprovalPolicy::REVERSAL_REQUESTS, $workflows, 'finance approves reversals');
        $this->assertNotContains(ApprovalPolicy::CAPITAL_CONTRIBUTIONS, $workflows, 'finance has no capital permission');
        $this->assertNotContains(ApprovalPolicy::SHARE_ISSUANCES, $workflows);
        $this->assertNotContains(ApprovalPolicy::WRITE_OFFS, $workflows);
    }

    public function test_items_of_another_company_are_never_listed(): void
    {
        $other = $this->signInAdmin();
        $this->seedPending($other, $other->branch_id);

        $this->actingAs($this->admin)->getJson('/api/v1/approvals/pending')->assertOk()->assertJsonPath('data.total_count', 0);
        $this->actingAs($other)->getJson('/api/v1/approvals/pending')->assertOk();
        $this->assertGreaterThanOrEqual(count(PendingApprovals::GROUPS), $this->getJson('/api/v1/approvals/pending')->json('data.total_count'));
    }

    public function test_branch_scoped_users_see_only_their_branches(): void
    {
        $second = Branch::factory()->create(['company_id' => $this->admin->company_id]);
        $this->seedPending($this->admin, $this->admin->branch_id);
        $this->seedPending($this->admin, $second->id);

        $manager = $this->secondApprover($this->admin, 'branch_manager');
        $manager->permissionOverrides()->createMany([
            ['permission' => 'approvals.view', 'granted' => true],
            ['permission' => 'payments.verify', 'granted' => true],
            ['permission' => 'salary_advance.manage', 'granted' => true],
        ]);
        $groups = collect($this->actingAs($manager->fresh())->getJson('/api/v1/approvals/pending')->assertOk()->json('data.groups'))->keyBy('workflow');

        $branch = Branch::findOrFail($this->admin->branch_id)->name;
        foreach ([ApprovalPolicy::LOAN_APPROVALS, ApprovalPolicy::TELLER_DEPOSITS, ApprovalPolicy::BRANCH_RECEIPTS, ApprovalPolicy::SALARY_ADVANCES, ApprovalPolicy::EXPENSES] as $workflow) {
            $this->assertSame(1, $groups[$workflow]['count'], $workflow);
            $this->assertSame([$branch], array_values(array_unique(array_column($groups[$workflow]['rows'], 'branch'))), $workflow);
        }

        $all = collect($this->actingAs($this->secondApprover($this->admin))->getJson('/api/v1/approvals/pending')->json('data.groups'))->keyBy('workflow');
        $this->assertSame(2, $all[ApprovalPolicy::LOAN_APPROVALS]['count']);
        $this->assertSame(2, $all[ApprovalPolicy::BRANCH_RECEIPTS]['count']);
    }

    public function test_pending_allowances_and_negligence_deductions_follow_the_finance_approval_and_segregation_rules(): void
    {
        $hr = $this->secondApprover($this->admin, 'hr');
        $hr->permissionOverrides()->create(['permission' => 'approvals.view', 'granted' => true]);
        $hr = $hr->fresh();
        $this->seedPending($hr, $this->admin->branch_id);
        $receiver = StaffAllowance::sole()->employee;
        $sections = [PendingApprovals::ALLOWANCES => ['/hrm/allowances', 10500.0], PendingApprovals::NEGLIGENCE_DEDUCTIONS => ['/hrm/negligence-deductions', 10700.0]];

        // Finance (payroll.pay) sees and may approve both.
        $finance = collect($this->actingAs($this->secondApprover($this->admin, 'finance'))->getJson('/api/v1/approvals/pending')->assertOk()->json('data.groups'))->keyBy('workflow');
        foreach ($sections as $workflow => [$link, $amount]) {
            $this->assertSame(1, $finance[$workflow]['count'], $workflow);
            $this->assertEquals($amount, $finance[$workflow]['amount'], $workflow);
            $this->assertSame($link, $finance[$workflow]['rows'][0]['link']);
            $this->assertSame($hr->full_name, $finance[$workflow]['rows'][0]['requested_by']);
            $this->assertTrue($finance[$workflow]['rows'][0]['can_approve'], $workflow);
        }

        // HR (the creator, without payroll.pay) sees them view-only.
        $own = collect($this->actingAs($hr)->getJson('/api/v1/approvals/pending')->assertOk()->json('data.groups'))->keyBy('workflow');
        foreach (array_keys($sections) as $workflow) {
            $this->assertFalse($own[$workflow]['rows'][0]['can_approve'], $workflow);
            $this->assertNull($own[$workflow]['rows'][0]['approve_blocked_reason'], $workflow);
        }

        // The receiving employee, even holding payroll.pay, is blocked; the payroll policy + explicit grant lift it.
        $receiver->update(['role_id' => $this->admin->company->roles()->where('key', 'finance')->value('id')]);
        $receiver = $receiver->fresh();
        $blocked = collect($this->actingAs($receiver)->getJson('/api/v1/approvals/pending')->assertOk()->json('data.groups'))->keyBy('workflow');
        foreach (array_keys($sections) as $workflow) {
            $this->assertFalse($blocked[$workflow]['rows'][0]['can_approve'], $workflow);
            $this->assertSame(SegregationOfDuties::INITIATOR_MESSAGE, $blocked[$workflow]['rows'][0]['approve_blocked_reason'], $workflow);
        }

        $this->grantSelfApproval($receiver, false);
        $this->allowSelfApprovalPolicy((int) $receiver->company_id, [ApprovalPolicy::PAYROLL]);
        $lifted = collect($this->actingAs($receiver->fresh())->getJson('/api/v1/approvals/pending')->json('data.groups'))->keyBy('workflow');
        foreach (array_keys($sections) as $workflow) {
            $this->assertTrue($lifted[$workflow]['rows'][0]['can_approve'], $workflow);
        }

        // Approved items leave the list.
        StaffAllowance::query()->update(['status' => StaffAllowance::STATUS_APPROVED]);
        NegligenceDeduction::query()->update(['status' => NegligenceDeduction::STATUS_APPROVED]);
        $after = collect($this->getJson('/api/v1/approvals/pending')->json('data.groups'))->keyBy('workflow');
        foreach (array_keys($sections) as $workflow) {
            $this->assertSame(0, $after[$workflow]['count'], $workflow);
        }
    }

    /**
     * One pending item of every workflow, initiated by $initiator in the given branch.
     */
    private function seedPending(Employee $initiator, int $branchId): void
    {
        $companyId = (int) $initiator->company_id;
        $bank = BankAccount::create(['company_id' => $companyId, 'name' => 'NMB '.$branchId]);
        $customer = Customer::factory()->create(['company_id' => $companyId, 'branch_id' => $branchId]);
        $holder = ShareHolder::create(['company_id' => $companyId, 'first_name' => 'ALPHA', 'last_name' => 'HOLDER', 'mobile' => '0777', 'email' => "alpha{$branchId}@example.com", 'date_of_birth' => '1990-01-01']);
        $staff = Employee::factory()->create(['company_id' => $companyId, 'branch_id' => $branchId, 'role_id' => $initiator->company->roles()->where('key', 'teller')->value('id')]);

        $type = ExpenseType::create(['company_id' => $companyId, 'scope' => 'branch', 'name' => 'UMEME']);
        ExpenseRequest::create(['company_id' => $companyId, 'scope' => 'branch', 'branch_id' => $branchId, 'expense_type_id' => $type->id, 'employee_id' => $initiator->id, 'amount' => 1000, 'status' => 'pending', 'request_date' => today()]);
        FloatTransfer::create(['company_id' => $companyId, 'type' => 'company_to_branch', 'to_branch_id' => $branchId, 'from_account' => 'company_cash', 'to_account' => 'principal', 'amount' => 2000, 'status' => 'pending', 'transfer_date' => today(), 'requested_by' => $initiator->id]);
        BankTransfer::create(['company_id' => $companyId, 'type' => 'petty_cash_to_branch', 'branch_id' => $branchId, 'branch_account' => 'petty_cash', 'bank_account_id' => $bank->id, 'employee_id' => $initiator->id, 'amount' => 3000, 'status' => 'pending', 'transfer_date' => today()]);
        HqTransaction::create(['company_id' => $companyId, 'employee_id' => $initiator->id, 'from_account' => 'hq_interest', 'to_account' => 'hq_disbursement', 'amount' => 4000, 'status' => 'pending']);
        Capital::create(['company_id' => $companyId, 'share_holder_id' => $holder->id, 'amount' => 5000, 'pay_method' => 'CASH', 'receiving_account' => 'company_cash', 'status' => 'pending', 'recorded_by' => $initiator->id, 'contributed_at' => now()]);
        app(AssetRegistry::class)->contribute($holder, [
            'asset_type' => 'furniture', 'name' => 'Desk', 'description' => 'Office desk', 'quantity' => 1, 'unit_value' => 6000, 'condition' => 'used',
            'contribution_date' => today()->toDateString(), 'branch_id' => $branchId, 'valuation_method' => 'agreed_value', 'valuation_date' => today()->toDateString(), 'specifications' => [],
        ], $initiator);
        ShareIssuanceRequest::create(['company_id' => $companyId, 'share_holder_id' => $holder->id, 'shares' => 10, 'price_per_share' => 700, 'total_amount' => 7000, 'issue_date' => today(), 'pay_method' => 'CASH', 'status' => 'pending', 'requested_by' => $initiator->id]);
        DividendDeclarationRequest::create(['company_id' => $companyId, 'period' => today()->startOfMonth()->subMonths($branchId), 'status' => 'pending', 'profit_amount' => 100000, 'dividend_percent' => 30, 'dividend_amount' => 27000, 'reinvest_percent' => 70, 'reinvest_amount' => 63000, 'requested_by' => $initiator->id, 'requested_at' => now()]);

        $pendingLoan = Loan::factory()->create(['customer_id' => $customer->id, 'employee_id' => $staff->id, 'status' => LoanStatus::PendingManagerApproval]);
        $activeLoan = Loan::factory()->create(['customer_id' => $customer->id, 'loan_category_id' => $pendingLoan->loan_category_id, 'status' => LoanStatus::Active, 'amount_approved' => 100000, 'disbursed_at' => now()]);
        WriteOffRequest::create(['company_id' => $companyId, 'branch_id' => $branchId, 'loan_id' => $activeLoan->id, 'status' => 'pending', 'reason' => 'Defaulted', 'requested_by' => $initiator->id]);
        LegacyImport::create(['company_id' => $companyId, 'branch_id' => $branchId, 'module' => LegacyImport::MODULE_PENALTY, 'file_name' => 'penalty.csv', 'status' => LegacyImport::STATUS_PENDING, 'totals' => ['importable_rows' => 1, 'penalty_outstanding' => 5000], 'uploaded_by' => $initiator->id, 'uploaded_at' => now(), 'submitted_by' => $initiator->id, 'submitted_at' => now()]);
        ReversalRequest::create(['company_id' => $companyId, 'branch_id' => $branchId, 'loan_id' => $activeLoan->id, 'type' => ReversalRequest::DISBURSEMENT, 'subject_type' => $activeLoan->getMorphClass(), 'subject_id' => $activeLoan->id, 'amount' => 100000, 'status' => 'pending', 'reason' => 'Sent twice', 'requested_by' => $initiator->id]);

        $advanceCategory = SalaryAdvanceCategory::create(['company_id' => $companyId, 'name' => 'SA', 'interest_rate' => 10, 'amount_from' => 1000, 'amount_to' => 50000]);
        SalaryAdvance::create(['company_id' => $companyId, 'branch_id' => $branchId, 'customer_id' => $customer->id, 'salary_advance_category_id' => $advanceCategory->id, 'employee_id' => $staff->id, 'amount' => 8000, 'interest_rate' => 10, 'total_payable' => 8800, 'status' => 'pending']);

        $loanCategory = StaffLoanCategory::create(['company_id' => $companyId, 'name' => 'SL', 'amount_from' => 1000, 'amount_to' => 10000, 'interest_rate' => 10, 'duration' => 'monthly', 'repayment_from' => 1, 'repayment_to' => 3, 'fee' => 0]);
        StaffLoan::create(['company_id' => $companyId, 'branch_id' => $branchId, 'employee_id' => $staff->id, 'staff_loan_category_id' => $loanCategory->id, 'amount_applied' => 9000, 'duration' => 'monthly', 'sessions' => 2, 'reason' => 'Fees', 'status' => 'submitted', 'requested_by' => $initiator->id]);
        $staffAdvanceCategory = StaffSalaryAdvanceCategory::create(['company_id' => $companyId, 'name' => 'SSA', 'amount_from' => 1000, 'amount_to' => 10000, 'fee' => 0]);
        StaffSalaryAdvance::create(['company_id' => $companyId, 'branch_id' => $branchId, 'employee_id' => $staff->id, 'staff_salary_advance_category_id' => $staffAdvanceCategory->id, 'amount' => 9500, 'status' => 'submitted', 'requested_by' => $initiator->id]);
        PayrollRun::firstOrCreate(['company_id' => $companyId, 'period' => today()->startOfMonth()], ['status' => PayrollRun::STATUS_DRAFT, 'prepared_by' => $initiator->id, 'total_net' => 10000]);
        StaffAllowance::create(['company_id' => $companyId, 'branch_id' => $branchId, 'employee_id' => $staff->id, 'amount' => 10500, 'reason' => 'overtime', 'payroll_period' => today()->startOfMonth(), 'recurring' => false, 'status' => StaffAllowance::STATUS_PENDING, 'created_by' => $initiator->id]);
        NegligenceDeduction::create(['company_id' => $companyId, 'branch_id' => $branchId, 'employee_id' => $staff->id, 'amount' => 10700, 'recovered_amount' => 0, 'reason' => 'Cash shortage', 'status' => NegligenceDeduction::STATUS_PENDING, 'created_by' => $initiator->id]);

        TellerDeposit::create(['company_id' => $companyId, 'branch_id' => $branchId, 'employee_id' => $staff->id, 'bank_account_id' => $bank->id, 'slip_number' => "S-{$branchId}", 'amount' => 11000, 'deposit_date' => today(), 'status' => TellerDeposit::STATUS_PENDING]);
        Payment::create(['company_id' => $companyId, 'branch_id' => $branchId, 'customer_id' => $customer->id, 'loan_id' => $activeLoan->id, 'employee_id' => $initiator->id, 'amount' => 12000, 'channel' => 'VODACOM', 'transaction_id' => "TX-{$branchId}", 'paid_on' => today(), 'source' => Payment::SOURCE_TELLER, 'status' => 'pending_approval']);
    }
}
