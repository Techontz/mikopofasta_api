<?php

namespace Tests\Feature\Api\Customers;

use App\Enums\Account;
use App\Enums\LoanStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranchTransfer;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanTransaction;
use App\Services\LoanService;
use App\Services\SavingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Payments\InteractsWithRepayments;
use Tests\TestCase;

/**
 * Enhancement §2.3: a customer moves branch with one profile; open loans and balances follow, history stays.
 */
class CustomerBranchTransferTest extends TestCase
{
    use InteractsWithRepayments, RefreshDatabase;

    private Employee $admin;

    private Branch $from;

    private Branch $to;

    private Loan $loan;

    private Customer $customer;

    private Employee $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
        $this->from = Branch::findOrFail($this->admin->branch_id);
        $this->to = Branch::factory()->create(['company_id' => $this->admin->company_id]);
        $this->loan = $this->activeLoan($this->admin);
        $this->customer = $this->loan->customer;
        $this->manager = $this->employeeWithRole($this->admin, 'branch_manager', $this->from->id);
        app(LoanService::class)->deposit($this->loan, 40000, CarbonImmutable::today(), 'CASH', $this->admin);
    }

    public function test_an_approved_transfer_moves_the_customer_and_open_loan_and_keeps_history_with_the_old_branch(): void
    {
        $paidBefore = LoanTransaction::where('loan_id', $this->loan->id)->where('type', 'deposit')->sole();
        $receivableBefore = $this->balance($this->admin, Account::LoanReceivable, $this->from->id);
        $this->assertGreaterThan(0, $receivableBefore);

        $transferId = $this->actingAs($this->manager)->postJson("/api/v1/customers/{$this->customer->id}/branch-transfers", ['to_branch_id' => $this->to->id, 'reason' => 'Customer moved to the new town.'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->assertSame($this->from->id, (int) $this->customer->refresh()->branch_id, 'Nothing moves before approval.');

        $this->actingAs($this->admin)->postJson("/api/v1/customers/branch-transfers/{$transferId}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertSame(1, Customer::count());
        $this->assertSame($this->to->id, (int) $this->customer->refresh()->branch_id);
        $this->assertSame($this->to->id, (int) $this->loan->refresh()->branch_id);
        $this->assertSame($this->from->id, (int) $paidBefore->refresh()->branch_id, 'Payments received at the old branch stay there.');

        $this->assertEqualsWithDelta(0, $this->balance($this->admin, Account::LoanReceivable, $this->from->id), 0.01);
        $this->assertEqualsWithDelta($receivableBefore, $this->balance($this->admin, Account::LoanReceivable, $this->to->id), 0.01);

        $transfer = CustomerBranchTransfer::findOrFail($transferId);
        $this->assertSame([$this->manager->id, $this->admin->id], [$transfer->requested_by, $transfer->decided_by]);
        $this->assertNotNull($transfer->journal_entry_id);
        $this->assertTrue(AuditLog::where('action', 'Customer.branch_transferred')->where('auditable_id', $this->customer->id)->exists());
    }

    public function test_repayments_after_the_transfer_settle_the_new_branch_receivable(): void
    {
        $this->approvedTransfer();

        app(LoanService::class)->deposit($this->loan->refresh(), 90000, CarbonImmutable::today(), 'CASH', $this->admin);

        $this->assertSame(LoanStatus::Closed, $this->loan->refresh()->status);
        $this->assertEqualsWithDelta(0, $this->balance($this->admin, Account::LoanReceivable, $this->to->id), 0.01);
        $this->assertEqualsWithDelta(0, $this->balance($this->admin, Account::LoanReceivable, $this->from->id), 0.01);
        $this->assertSame($this->to->id, (int) LoanTransaction::where('loan_id', $this->loan->id)->latest('id')->value('branch_id'));
    }

    public function test_savings_balance_follows_the_customer(): void
    {
        app(SavingService::class)->deposit($this->customer, 25000, $this->admin);

        $this->approvedTransfer();

        $this->assertEqualsWithDelta(0, $this->balance($this->admin, Account::HqSaving, $this->from->id), 0.01);
        $this->assertEqualsWithDelta(25000, $this->balance($this->admin, Account::HqSaving, $this->to->id), 0.01);
        app(SavingService::class)->withdraw($this->customer->refresh(), 25000, SavingService::TAKEN, $this->admin);
        $this->assertEqualsWithDelta(0, $this->balance($this->admin, Account::HqSaving, $this->to->id), 0.01);
    }

    public function test_ended_loans_stay_with_the_old_branch(): void
    {
        $closed = Loan::factory()->create(['customer_id' => $this->customer->id, 'status' => LoanStatus::Closed]);

        $this->approvedTransfer();

        $this->assertSame($this->from->id, (int) $closed->refresh()->branch_id);
    }

    public function test_the_requester_cannot_approve_and_only_approvers_decide(): void
    {
        $crm = $this->employeeWithRole($this->admin, 'crm');
        $transferId = $this->actingAs($crm)->postJson("/api/v1/customers/{$this->customer->id}/branch-transfers", ['to_branch_id' => $this->to->id, 'reason' => 'Moved'])->assertCreated()->json('data.id');

        $this->actingAs($this->manager)->postJson("/api/v1/customers/branch-transfers/{$transferId}/approve")->assertForbidden();
        $this->actingAs($crm)->postJson("/api/v1/customers/branch-transfers/{$transferId}/approve")->assertForbidden();

        $otherAdmin = $this->employeeWithRole($this->admin, 'admin');
        CustomerBranchTransfer::whereKey($transferId)->update(['requested_by' => $otherAdmin->id]);
        $this->actingAs($otherAdmin)->postJson("/api/v1/customers/branch-transfers/{$transferId}/approve")->assertForbidden();

        $this->actingAs($this->admin)->postJson("/api/v1/customers/branch-transfers/{$transferId}/reject", ['reason' => 'Not confirmed'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame($this->from->id, (int) $this->customer->refresh()->branch_id);
    }

    public function test_a_teller_cannot_request_and_a_branch_cannot_transfer_another_branchs_customer(): void
    {
        $this->actingAs($this->employeeWithRole($this->admin, 'teller'))
            ->postJson("/api/v1/customers/{$this->customer->id}/branch-transfers", ['to_branch_id' => $this->to->id, 'reason' => 'x'])->assertForbidden();
        $this->actingAs($this->employeeWithRole($this->admin, 'branch_manager', $this->to->id))
            ->postJson("/api/v1/customers/{$this->customer->id}/branch-transfers", ['to_branch_id' => $this->to->id, 'reason' => 'x'])->assertNotFound();
    }

    public function test_a_loan_being_disbursed_blocks_the_transfer_and_duplicates_are_refused(): void
    {
        $this->actingAs($this->manager)->postJson("/api/v1/customers/{$this->customer->id}/branch-transfers", ['to_branch_id' => $this->from->id, 'reason' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('to_branch_id');

        $this->actingAs($this->manager)->postJson("/api/v1/customers/{$this->customer->id}/branch-transfers", ['to_branch_id' => $this->to->id, 'reason' => 'x'])->assertCreated();
        $this->actingAs($this->manager)->postJson("/api/v1/customers/{$this->customer->id}/branch-transfers", ['to_branch_id' => $this->to->id, 'reason' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('customer');

        Loan::factory()->create(['customer_id' => $this->customer->id, 'status' => LoanStatus::AwaitingDisbursement]);
        $this->actingAs($this->admin)->postJson('/api/v1/customers/branch-transfers/'.CustomerBranchTransfer::value('id').'/approve')
            ->assertUnprocessable()->assertJsonValidationErrors('customer');
        $this->assertSame($this->from->id, (int) $this->customer->refresh()->branch_id);
        $this->assertSame($this->from->id, (int) $this->loan->refresh()->branch_id);
    }

    public function test_transfer_targets_list_every_branch_only_for_employees_who_may_transfer(): void
    {
        $this->actingAs($this->manager)->getJson('/api/v1/options/branches?transfer_targets=1')->assertOk()
            ->assertJsonFragment(['value' => (string) $this->to->id]);
        $this->actingAs($this->manager)->getJson('/api/v1/options/branches')->assertOk()
            ->assertJsonMissing(['value' => (string) $this->to->id]);
        $this->actingAs($this->employeeWithRole($this->admin, 'teller'))->getJson('/api/v1/options/branches?transfer_targets=1')->assertOk()
            ->assertJsonMissing(['value' => (string) $this->to->id]);
    }

    private function approvedTransfer(): void
    {
        $id = $this->actingAs($this->manager)->postJson("/api/v1/customers/{$this->customer->id}/branch-transfers", ['to_branch_id' => $this->to->id, 'reason' => 'Moved'])->assertCreated()->json('data.id');
        $this->actingAs($this->admin)->postJson("/api/v1/customers/branch-transfers/{$id}/approve")->assertOk();
    }
}
